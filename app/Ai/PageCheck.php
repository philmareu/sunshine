<?php

namespace App\Ai;

use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * What a scan is likely to make of a page, checked from Sunshine's server, where a scan reads it too (PageRead):
 * whether the page answers, whether it is content Sunshine reads (HTML, feeds, iCal, JSON or text), whether robots.txt
 * lets Anthropic's fetcher in (Sunshine follows the same rules), how much of it fits before PageRead::MAX_CHARACTERS
 * cuts it off, and how far ahead its dates reach. Pages Sunshine can't read are left to the scout's fetch, which comes
 * from Anthropic's servers that some sites treat differently, so a scan's note on a source is the final word.
 *
 * The check and the reads run on Sunshine's server, and the address can come from anywhere on the web, so get() only
 * ever connects to public addresses: each hop of a redirect is checked, and the request is pinned to the address
 * that was checked so DNS can't swap it in between.
 */
class PageCheck
{
    /**
     * The name Anthropic's fetcher goes by when it fetches a page for a user.
     */
    public const FETCHER = 'Claude-User';

    /**
     * Anthropic's other crawlers, which some sites turn away by name.
     */
    public const OTHER_AGENTS = ['ClaudeBot', 'anthropic-ai'];

    protected const USER_AGENT = 'Sunshine source check';

    protected const MAX_REDIRECTS = 5;

    /**
     * The most of a page get() downloads, so a page can't fill the worker's memory.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * The only ports get() connects to, as Sunshine's browser.
     */
    protected const PORTS = [80, 443];

    /**
     * Private, shared and reserved ranges get() never connects to, the same as Sunshine's browser (browser/render.mjs):
     * the server's own networks (a tailnet's 100.64.0.0/10 among them), cloud metadata services, and IPv6 ranges that
     * carry an IPv4 address inside them (NAT64, 6to4, Teredo).
     */
    protected const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Looks up a host's addresses in place of DNS. Tests set it, since their sites don't exist.
     *
     * @var (Closure(string): list<string>)|null
     */
    protected static ?Closure $resolver = null;

    protected const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    protected const MONTH = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\b\.?';

    /**
     * @param  bool  $oneDay  Whether the page is one day's page of a calendar with one for each day, so its dates are
     *                        meant to stop at that day.
     * @param  bool  $browser  Whether to check the page as Sunshine's browser shows it, for one that fills in its
     *                         events with JavaScript.
     * @return array{url: string, status: ?int, content_type: ?string, approx_tokens: ?int, upcoming_dates: ?int, latest_date: ?string, robots_turns_away: list<string>, problems: list<string>, summary: string}
     */
    public static function run(string $url, CarbonImmutable $today, int $daysAhead, bool $oneDay = false, bool $browser = false): array
    {
        // The dates found on a page are days, made at midnight in the app's timezone, so today has to be one too: at
        // midnight where the user lives it is already hours into the day in UTC, and today's date would count as past.
        $today = CarbonImmutable::parse($today->toDateString());

        $result = [
            'url' => $url,
            'status' => null,
            'content_type' => null,
            'approx_tokens' => null,
            'upcoming_dates' => null,
            'latest_date' => null,
            'robots_turns_away' => static::turnedAway($url),
            'problems' => [],
        ];

        if (in_array(self::FETCHER, $result['robots_turns_away'], true)) {
            $result['problems'][] = 'robots.txt turns away '.self::FETCHER.', the name Anthropic\'s fetcher uses, whose rules Sunshine follows too, so a scan can\'t read it.';
        } elseif ($result['robots_turns_away'] !== []) {
            $result['problems'][] = 'robots.txt turns away '.implode(' and ', $result['robots_turns_away']).', though not '.self::FETCHER.', so a scan should still get in.';
        }

        try {
            $response = static::get($url, 20);
        } catch (ConnectionException) {
            return static::summarize($daysAhead, [...$result, 'problems' => [...$result['problems'], 'Couldn\'t connect to it.']]);
        } catch (RuntimeException $exception) {
            return static::summarize($daysAhead, [...$result, 'problems' => [...$result['problems'], $exception->getMessage()]]);
        }

        $result['status'] = $response->status();
        $result['content_type'] = Str::of((string) $response->header('Content-Type'))->before(';')->trim()->lower()->toString() ?: null;

        if ($browser) {
            try {
                $text = PageRead::shown((string) ($response->effectiveUri() ?? $url));
            } catch (RuntimeException $exception) {
                return static::summarize($daysAhead, [...$result, 'problems' => [...$result['problems'], $exception->getMessage()]]);
            }

            return static::summarize($daysAhead, static::checkText($result, $text, $today, $daysAhead, $oneDay, $browser));
        }

        if (! $response->successful()) {
            $result['problems'][] = "It answered this check with {$response->status()}, so a scan leaves it to the scout's fetch. Some sites turn away servers but let Anthropic's fetcher in, so a scan may still read it.";

            return static::summarize($daysAhead, $result);
        }

        if ($result['content_type'] === 'application/pdf') {
            $result['problems'][] = 'It is a PDF. A scan leaves these to the scout\'s fetch, which reads them, but this check can\'t look inside it.';

            return static::summarize($daysAhead, $result);
        }

        if (! PageRead::reads($result['content_type'])) {
            $result['problems'][] = "Its content type is {$result['content_type']}, which neither Sunshine nor Anthropic's web fetch reads.";
        }

        return static::summarize($daysAhead, static::checkText($result, PageRead::text($response, $result['content_type'], $url), $today, $daysAhead, $oneDay, $browser));
    }

    /**
     * What a check makes of a page's text: how long it is, and how far ahead its dates reach.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected static function checkText(array $result, string $text, CarbonImmutable $today, int $daysAhead, bool $oneDay, bool $browser): array
    {
        $result['approx_tokens'] = (int) ceil(mb_strlen($text) / 4);

        if (mb_strlen($text) > PageRead::MAX_CHARACTERS) {
            $result['problems'][] = sprintf('At about %s tokens it is cut off after about %s, so a scan only reads the start of it.', number_format($result['approx_tokens']), number_format(PageRead::MAX_CHARACTERS / 4));
        }

        $ahead = static::dates($text, $today)->filter(fn (CarbonImmutable $date): bool => $date->gte($today));
        $result['upcoming_dates'] = $ahead->filter(fn (CarbonImmutable $date): bool => $date->lte($today->addDays($daysAhead)))->count();
        $result['latest_date'] = $ahead->max()?->toDateString();

        if ($ahead->isEmpty()) {
            $result['problems'][] = $browser
                ? "No dates from {$today->toDateString()} on in its text, even in Sunshine's browser, so it may only list past ones."
                : "No dates from {$today->toDateString()} on in its text. Its events may be filled in by JavaScript: check it again in Sunshine's browser. Or it only lists past ones.";
        } elseif (! $oneDay && $ahead->max()->lt($today->addWeek())) {
            $result['problems'][] = "Its dates only reach {$result['latest_date']}, so it may list just the next few days.";
        }

        return $result;
    }

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveHostsUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Whether robots.txt lets Anthropic's fetcher open this page, which Sunshine takes as its own rule when it reads
     * a page in the fetcher's place.
     */
    public static function allows(string $url): bool
    {
        return ! in_array(self::FETCHER, static::turnedAway($url), true);
    }

    /**
     * Get a page from its public address only, following redirects one hop at a time and checking each the same way.
     * Each address is passed to $before first, which can refuse it by throwing.
     *
     * @param  (Closure(string): void)|null  $before
     *
     * @throws ConnectionException
     * @throws RuntimeException with the problem to report, when the page can't be got
     */
    public static function get(string $url, int $timeout, string $userAgent = self::USER_AGENT, ?Closure $before = null): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $host = trim($parts['host'] ?? '', '[]');
            $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443);

            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
                throw new RuntimeException('It leads somewhere that isn\'t a web page.');
            }

            if (! in_array($port, self::PORTS, true)) {
                throw new RuntimeException('It leads to a port other than a web page\'s, so it wasn\'t checked.');
            }

            if ($before !== null) {
                $before($url);
            }

            $address = static::publicAddress($host);
            $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

            $tooBig = false;

            try {
                $response = Http::withUserAgent($userAgent)
                    ->timeout($timeout)
                    ->withOptions(['allow_redirects' => false, 'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"],
                        CURLOPT_MAXFILESIZE_LARGE => self::MAX_BYTES,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_XFERINFOFUNCTION => function ($handle, int $total, int $downloaded) use (&$tooBig): int {
                            $tooBig = $downloaded > self::MAX_BYTES;

                            return $tooBig ? 1 : 0;
                        },
                    ]])
                    ->get($url);
            } catch (ConnectionException $exception) {
                throw $tooBig || str_contains($exception->getMessage(), 'Maximum file size exceeded')
                    ? new RuntimeException('It\'s too big to read.')
                    : $exception;
            }

            if (! $response->redirect() || blank($response->header('Location'))) {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
        }

        throw new RuntimeException('It redirects too many times.');
    }

    /**
     * The public address a host leads to. Anything in a private or reserved range, such as the server's own network
     * or a cloud metadata service, is refused.
     *
     * @throws RuntimeException
     */
    protected static function publicAddress(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (static::$resolver ?? static::lookUp(...))(strtolower(rtrim($host, '.')));

        if ($addresses === []) {
            throw new RuntimeException('Its site couldn\'t be found.');
        }

        foreach ($addresses as $address) {
            if (! static::isPublic($address)) {
                throw new RuntimeException('It leads to a private address, so it wasn\'t checked.');
            }
        }

        return $addresses[0];
    }

    /**
     * Whether an address is outside every blocked range. An IPv4 address written as IPv6 (::ffff:10.0.0.1) is checked
     * as the IPv4 address it is.
     */
    public static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $address = (string) inet_ntop(substr($packed, 12));
        }

        return ! IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }

    /**
     * @return list<string>
     */
    protected static function lookUp(string $host): array
    {
        return [
            ...gethostbynamel($host) ?: [],
            ...array_column(rescue(fn () => dns_get_record($host, DNS_AAAA), [], false) ?: [], 'ipv6'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected static function summarize(int $daysAhead, array $result): array
    {
        $result['summary'] = $result['problems'] === []
            ? sprintf('Looks readable: dates on %d of the next %d days, reaching %s, in about %s tokens.', $result['upcoming_dates'], $daysAhead, $result['latest_date'], number_format($result['approx_tokens']))
            : implode(' ', $result['problems']);

        return $result;
    }

    /**
     * The dates written in some text, in the forms event calendars and feeds use: 2026-11-05, iCal's 20261105T...,
     * 11/05/2026, "Nov 5, 2026", "5 Nov 2026" and, without a year, "Nov 5", taken as the one nearest today.
     *
     * @return Collection<int, CarbonImmutable>
     */
    protected static function dates(string $text, CarbonImmutable $today): Collection
    {
        $found = [];
        $month = fn (string $name): int => array_search(ucfirst(strtolower(substr($name, 0, 3))), self::MONTHS, true) + 1;

        preg_match_all('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        }

        preg_match_all('/:(20\d{2})(\d{2})(\d{2})(?:T\d{4,6}Z?)?\b/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        }

        preg_match_all('#\b(\d{1,2})/(\d{1,2})/(20\d{2})\b#', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = [(int) $match[3], (int) $match[1], (int) $match[2]];
        }

        preg_match_all('/\b'.self::MONTH.'\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(20\d{2})\b)?/i', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = [isset($match[3]) ? (int) $match[3] : null, $month($match[1]), (int) $match[2]];
        }

        preg_match_all('/\b(\d{1,2})\s+'.self::MONTH.',?\s+(20\d{2})\b/i', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = [(int) $match[3], $month($match[2]), (int) $match[1]];
        }

        return collect($found)
            ->map(function (array $parts) use ($today): ?CarbonImmutable {
                [$year, $month, $day] = $parts;

                $years = $year === null ? [$today->year - 1, $today->year, $today->year + 1] : [$year];

                return collect($years)
                    ->filter(fn (int $year): bool => checkdate($month, $day, $year))
                    ->map(fn (int $year): CarbonImmutable => CarbonImmutable::create($year, $month, $day))
                    ->sortBy(fn (CarbonImmutable $date): float => abs($date->diffInDays($today)))
                    ->first();
            })
            ->filter()
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();
    }

    /**
     * Which of Anthropic's agents the site's robots.txt turns away from this page. A missing robots.txt allows all.
     *
     * @return list<string>
     */
    protected static function turnedAway(string $url): array
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ":{$parts['port']}" : '');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? "?{$parts['query']}" : '');

        try {
            $robots = static::get("{$origin}/robots.txt", 10);
        } catch (ConnectionException|RuntimeException) {
            return [];
        }

        if (! $robots->successful()) {
            return [];
        }

        $groups = static::robotsGroups($robots->body());

        return array_values(array_filter(
            [self::FETCHER, ...self::OTHER_AGENTS],
            fn (string $agent): bool => ! static::robotsAllow($groups, $agent, $path),
        ));
    }

    /**
     * A robots.txt file's groups: the agents each names and its allow and disallow rules.
     *
     * @return list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>}>
     */
    protected static function robotsGroups(string $body): array
    {
        $groups = [];
        $namingAgents = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(Str::before($line, '#'));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map(trim(...), explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $namingAgents) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $namingAgents = true;
                }

                $groups[array_key_last($groups)]['agents'][] = strtolower($value);

                continue;
            }

            $namingAgents = false;

            if (in_array($field, ['allow', 'disallow'], true) && $groups !== [] && $value !== '') {
                $groups[array_key_last($groups)]['rules'][] = ['allow' => $field === 'allow', 'path' => $value];
            }
        }

        return $groups;
    }

    /**
     * Whether an agent may fetch a path: the rules of the groups naming it, or else of "*", where the longest
     * matching rule wins and Allow wins a tie.
     *
     * @param  list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>}>  $groups
     */
    protected static function robotsAllow(array $groups, string $agent, string $path): bool
    {
        $named = array_filter($groups, fn (array $group): bool => in_array(strtolower($agent), $group['agents'], true));
        $rules = collect($named ?: array_filter($groups, fn (array $group): bool => in_array('*', $group['agents'], true)))
            ->flatMap(fn (array $group): array => $group['rules']);

        $best = $rules
            ->filter(function (array $rule) use ($path): bool {
                $anchored = str_ends_with($rule['path'], '$');
                $pattern = str_replace('\*', '.*', preg_quote(rtrim($rule['path'], '$'), '#'));

                return (bool) preg_match('#^'.$pattern.($anchored ? '$' : '').'#', $path);
            })
            ->sortByDesc(fn (array $rule): int => strlen($rule['path']) * 2 + ($rule['allow'] ? 1 : 0))
            ->first();

        return $best === null || $best['allow'];
    }
}
