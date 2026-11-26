<?php

namespace App\Ai;

use App\Ai\Agents\PageReader;
use App\Models\AiUsage;
use App\Models\Search;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Sunshine's own read of a page a run opens, in the place of the scout's fetch. The page is got from Sunshine's
 * server through PageCheck's guard, following robots.txt as Anthropic's fetcher would on every address it leads to,
 * or for a page that fills in its events with JavaScript, shown in Sunshine's browser (PageBrowser) where that leads;
 * its text is boiled down (HTML keeps its links, so events keep their own pages); and PageReader, on a cheap model,
 * lists its events. A page Sunshine can't read, such as a PDF, one that turns the server away, or one PageReader fails
 * on, is left to the scout's fetch, as before.
 */
final class PageRead
{
    /**
     * The most of a page's text the reader gets, about 50,000 tokens: well under the 100,000 past which the cheap
     * model costs five times as much.
     */
    public const MAX_CHARACTERS = 200_000;

    /**
     * Less text than this is a page that shows its events with JavaScript or shows Sunshine something else, so it is
     * left to the scout's fetch, which Anthropic may be served differently.
     */
    public const MIN_CHARACTERS = 200;

    protected const USER_AGENT = 'Sunshine (reads the event pages a family watches)';

    protected const TIMEOUT = 20;

    /**
     * @param  bool  $read  Whether Sunshine read the page, so the scout doesn't fetch it.
     * @param  list<array<string, mixed>>  $events  The events PageReader listed, as its schema gives them.
     * @param  ?string  $note  PageReader's note on the page, or why Sunshine couldn't read it.
     */
    public function __construct(public readonly bool $read, public readonly array $events = [], public readonly ?string $note = null) {}

    /**
     * Read a page for a run of a search, or for a page with one address for each day, its page for one of them,
     * recording what the read used.
     */
    public static function of(Source $source, Search $search, ?string $location = null, ?CarbonInterface $day = null): self
    {
        $address = $day ? $source->urlFor($day) : $source->url;

        try {
            $response = PageCheck::get($address, self::TIMEOUT, self::USER_AGENT, function (string $url): void {
                if (! PageCheck::allows($url)) {
                    throw new RuntimeException('robots.txt turns away '.PageCheck::FETCHER." from {$url}.");
                }
            });
        } catch (ConnectionException) {
            return new self(false, note: 'Sunshine couldn\'t connect to it.');
        } catch (RuntimeException $exception) {
            return new self(false, note: $exception->getMessage());
        }

        if ($source->needs_browser) {
            // What the server sends doesn't matter for a page shown in a browser: the fetch only followed its
            // redirects, checking robots.txt and its address on each, to where the browser opens it.
            try {
                $text = self::shown((string) ($response->effectiveUri() ?? $address));
            } catch (RuntimeException $exception) {
                return new self(false, note: $exception->getMessage());
            }

            if (mb_strlen($text) < self::MIN_CHARACTERS) {
                return new self(false, note: 'Its text is nearly empty even in Sunshine\'s browser.');
            }
        } else {
            if (! $response->successful()) {
                return new self(false, note: "It answered Sunshine with {$response->status()}.");
            }

            $contentType = Str::of((string) $response->header('Content-Type'))->before(';')->trim()->lower()->toString() ?: null;

            if (! self::reads($contentType)) {
                return new self(false, note: $contentType === null ? 'It didn\'t say what kind of page it is.' : "Sunshine doesn't read {$contentType}.");
            }

            $text = self::text($response, $contentType, (string) ($response->effectiveUri() ?? $address));

            if (mb_strlen($text) < self::MIN_CHARACTERS) {
                return new self(false, note: 'Its text is nearly empty, so its events may be shown with JavaScript. Marking it as needing a browser may help.');
            }
        }

        try {
            $reply = (new PageReader($search, $location))->prompt(
                ($source->name ? "{$source->name}".($day ? ' for '.$day->format('l, M j') : '').': ' : '').$address
                    ."\n\n<page>\n".Str::limit($text, self::MAX_CHARACTERS, "\n[cut off here]")."\n</page>",
            );
        } catch (Throwable $exception) {
            report($exception);

            return new self(false, note: 'Sunshine\'s reader failed on it.');
        }

        AiUsage::record($search, $reply, $location, $source);

        if (collect($reply->steps)->last()?->finishReason === FinishReason::Length || ! is_array($reply['events'] ?? null)) {
            return new self(false, note: 'Sunshine\'s reader ran out of room on it.');
        }

        $events = array_values($reply['events']);

        // The brief offers the page's address for an event without a link of its own, and a page for each day has none.
        if ($day !== null) {
            $events = array_map(fn (array $event): array => [...$event, 'url' => filled($event['url'] ?? null) ? $event['url'] : $address], $events);
        }

        return new self(true, $events, $reply['note'] ?? null);
    }

    /**
     * One read of a page with one address for each day, from its reads for each day: the events on every day that was
     * read. When none was, it is left to the scout's fetch.
     *
     * @param  array<string, self>  $days  The reads that arrived, by date.
     * @param  int  $total  How many days the run was to read it for.
     */
    public static function ofDays(array $days, int $total): self
    {
        $read = array_filter($days, fn (self $day): bool => $day->read);

        if ($read === []) {
            return new self(false, note: $days === [] ? 'Sunshine ran out of time to read it.' : reset($days)->note);
        }

        $note = count($read) === $total
            ? sprintf('Read for each of its %d days.', $total)
            : sprintf('Read for %d of its %d days.', count($read), $total);

        $failed = array_diff_key($days, $read);

        if ($failed !== []) {
            $note .= ' On '.CarbonImmutable::parse(array_key_first($failed))->format('M j').': '.reset($failed)->note;
        }

        return new self(true, array_merge(...array_values(array_map(fn (self $day): array => $day->events, $read))), $note);
    }

    /**
     * The read as plain data, which the cache keeps, as it unserializes no classes.
     *
     * @return array{read: bool, events: list<array<string, mixed>>, note: ?string}
     */
    public function toArray(): array
    {
        return ['read' => $this->read, 'events' => $this->events, 'note' => $this->note];
    }

    /**
     * @param  array{read: bool, events: list<array<string, mixed>>, note: ?string}  $read
     */
    public static function fromArray(array $read): self
    {
        return new self($read['read'], $read['events'], $read['note']);
    }

    /**
     * The text of a page as Sunshine's browser shows it.
     *
     * @throws RuntimeException with the problem to report, when it couldn't be shown
     */
    public static function shown(string $url): string
    {
        $shown = PageBrowser::show($url, self::USER_AGENT);

        if ($shown['status'] !== null && ($shown['status'] < 200 || $shown['status'] >= 300)) {
            throw new RuntimeException("It answered Sunshine's browser with {$shown['status']}.");
        }

        // A site can still send the browser elsewhere, and robots.txt is followed wherever it ends up.
        if ($shown['url'] !== $url && ! PageCheck::allows($shown['url'])) {
            throw new RuntimeException('robots.txt turns away '.PageCheck::FETCHER." from {$shown['url']}.");
        }

        return self::htmlText($shown['html'], $shown['url']);
    }

    /**
     * Whether Sunshine reads this kind of page: HTML, feeds and other XML, iCal, JSON and plain text. PDFs are left to
     * the scout's fetch, which reads them.
     */
    public static function reads(?string $contentType): bool
    {
        return $contentType !== null
            && (str_starts_with($contentType, 'text/') || str_contains($contentType, 'html') || str_contains($contentType, 'xml') || str_contains($contentType, 'json'));
    }

    /**
     * The page as text for the reader. HTML loses its scripts, styles and menus, and each link is followed by its
     * address the first time it comes up, in angle brackets. Feeds lose their tags, keeping each item to a line or
     * two. iCal, JSON and plain text are read as they are.
     */
    public static function text(Response $response, ?string $contentType, string $url): string
    {
        $body = $response->body();

        return match (true) {
            str_contains((string) $contentType, 'html') => self::htmlText($body, $url),
            str_contains((string) $contentType, 'xml') => self::feedText($body),
            default => trim($body),
        };
    }

    public static function htmlText(string $html, string $url): string
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($document);

        foreach (iterator_to_array($xpath->query('//head|//script|//style|//noscript|//template|//svg|//iframe|//nav|//select') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $linked = [];

        foreach (iterator_to_array($xpath->query('//a[@href]') ?: []) as $link) {
            $address = self::resolve($url, trim($link->getAttribute('href')));

            if ($address === null || isset($linked[$address]) || blank($link->textContent)) {
                continue;
            }

            $linked[$address] = true;
            $link->appendChild($document->createTextNode(" <{$address}>"));
        }

        foreach (iterator_to_array($xpath->query('//td|//th') ?: []) as $cell) {
            $cell->appendChild($document->createTextNode(' | '));
        }

        foreach (iterator_to_array($xpath->query('//br|//p|//div|//li|//tr|//h1|//h2|//h3|//h4|//h5|//h6|//article|//section|//header|//footer|//dt|//dd|//table|//ul|//ol|//main|//aside') ?: []) as $block) {
            $block->appendChild($document->createTextNode("\n"));
        }

        return self::lines($document->documentElement->textContent ?? '');
    }

    protected static function feedText(string $xml): string
    {
        // Atom gives links as attributes, and items run together once tags go, so mark both before taking tags out.
        $xml = preg_replace('/<link\b[^>]*\bhref="([^"]+)"[^>]*>/i', ' $1 ', $xml) ?? $xml;
        $xml = preg_replace('#</(item|entry)>#i', "\n\n", $xml) ?? $xml;
        $xml = str_replace(['<![CDATA[', ']]>'], ' ', $xml);

        $withoutTags = fn (string $markup): string => preg_replace('/<[^>]*>/', ' ', $markup) ?? $markup;

        // Feeds often carry escaped HTML, so tags are taken out again once entities are decoded.
        return self::lines($withoutTags(html_entity_decode($withoutTags($xml), ENT_QUOTES | ENT_HTML5)), keepGaps: true);
    }

    /**
     * Text with its whitespace squeezed: one line per block, without empty lines, or with a single empty line where
     * there were some, to keep a feed's items apart.
     */
    protected static function lines(string $text, bool $keepGaps = false): string
    {
        $lines = collect(preg_split('/\R/u', $text) ?: [])->map(fn (string $line): string => Str::squish($line));

        if (! $keepGaps) {
            return $lines->filter()->implode("\n");
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $lines->implode("\n")) ?? '');
    }

    /**
     * A link's full address, or null for one that leads nowhere worth listing: in-page anchors, scripts, email and
     * phone links.
     */
    protected static function resolve(string $base, string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|tel|data):/i', $href)) {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($href));
        } catch (Throwable) {
            return null;
        }
    }
}
