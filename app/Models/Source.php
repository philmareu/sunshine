<?php

namespace App\Models;

use App\Ai\PageRead;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;

/**
 * A page the user wants checked: an events calendar, a feed, or a single event's page. A watched page is opened by
 * every scan, in the run near home or the run for one of the user's other places; a page for one ad hoc search is
 * opened only by that search. Each run reads it with Sunshine's own PageRead, or leaves it to the scout's fetch when
 * Sunshine can't; afterwards it holds whether the page was actually read, how many events Sunshine read on it and why
 * it couldn't, and the scout's note on what it found.
 *
 * A page that fills in its events with JavaScript needs_browser: Sunshine shows it in headless Chromium before reading
 * it (PageBrowser).
 *
 * A page with one address for each day, such as cityguide.example.com/events/2026/11/07, is written with {date} where
 * the date goes, or {date:Y/m/d} to give its form in PHP's date format letters; a run reads it once for each day it
 * covers (Search::daysToRead()).
 */
#[Fillable(['user_id', 'search_id', 'name', 'url', 'place', 'needs_browser', 'checked_at', 'page_read', 'events_read', 'read_note', 'check_note'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * Where the date goes in the address of a page with one for each day: {date}, or {date:FORMAT} with PHP's date
     * format letters.
     */
    public const DATE_PLACEHOLDER = '/\{date(?::([^}]+))?\}/';

    /**
     * The most days a run reads such a page for.
     */
    public const MAX_DAYS = 31;

    /**
     * How many of its days the scout is asked to fetch such a page for, when Sunshine couldn't read it itself.
     */
    public const DAYS_TO_FETCH = 3;

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'needs_browser' => 'boolean',
            'page_read' => 'boolean',
            'events_read' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Search, $this>
     */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * The other place whose run of a scan opens this page, or null for the run near home, which also opens pages
     * for a place the user no longer lists.
     *
     * @param  list<string>  $places  The locations of the user's other places.
     */
    public function runsIn(array $places): ?string
    {
        return in_array($this->place, $places, true) ? $this->place : null;
    }

    /**
     * Whether the page has one address for each day.
     */
    public function isDated(): bool
    {
        return static::hasDate($this->url);
    }

    public static function hasDate(string $url): bool
    {
        return preg_match(self::DATE_PLACEHOLDER, $url) === 1;
    }

    /**
     * The page's address for a day, or its only address for a page without one for each day.
     */
    public function urlFor(CarbonInterface $day): string
    {
        return static::fillDate($this->url, $day);
    }

    public static function fillDate(string $url, CarbonInterface $day): string
    {
        return preg_replace_callback(self::DATE_PLACEHOLDER, fn (array $match): string => $day->format(($match[1] ?? '') ?: 'Y-m-d'), $url) ?? $url;
    }

    /**
     * Validates a page's address: a web address, which may have {date} or {date:FORMAT} where it changes day by day.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function addressRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $filled = is_string($value) ? static::fillDate($value, now()) : $value;

            if (Validator::make(['url' => $filled], ['url' => 'url:http,https'])->fails()) {
                $fail('The :attribute must be a web address starting with http or https. For a page with one address for each day, put {date} where the date goes, or {date:Y/m/d} to give its form.');
            }
        };
    }

    /**
     * Record what a run did with this page: whether it was read, by Sunshine itself or by the scout, going by the
     * fetches the provider reports rather than the scout's word; how many events Sunshine read on it and its
     * reader's note, or why Sunshine couldn't read it; and the scout's note on what it made of the page.
     */
    public function recordCheck(AgentResponse $response, ?PageRead $read = null): void
    {
        $fetched = collect($response->steps)
            ->flatMap(fn (Step $step): array => $step->providerToolCalls)
            ->contains(fn (ProviderToolCall $call): bool => $call->type === 'web_fetch_tool_result'
                && ($call->data['content']['type'] ?? null) === 'web_fetch_result'
                && $this->covers($call->data['content']['url'] ?? ''));

        $note = collect($response['sources'] ?? [])->first(fn (array $note): bool => $this->covers($note['url'] ?? ''));

        $this->update([
            'checked_at' => now(),
            'page_read' => ($read?->read ?? false) || $fetched,
            'events_read' => $read?->read ? count($read->events) : null,
            'read_note' => $read?->note,
            'check_note' => $note['note'] ?? null,
        ]);
    }

    /**
     * Whether an address is this page or a page under it, ignoring the scheme, "www.", a trailing slash and case.
     */
    protected function covers(string $url): bool
    {
        // A page with one address for each day covers every address up to where the date goes.
        $page = $this->isDated() ? Str::before($this->url, '{') : $this->url;

        return Str::startsWith(static::normalize($url), static::normalize($page));
    }

    protected static function normalize(string $url): string
    {
        return Str::of($url)->lower()->before('#')->replaceMatches('#^https?://(www\.)?#', '')->rtrim('/')->toString();
    }
}
