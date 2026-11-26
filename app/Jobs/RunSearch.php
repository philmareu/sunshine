<?php

namespace App\Jobs;

use App\Ai\Agents\EventScout;
use App\Ai\PageRead;
use App\Ai\TasteProfile;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Models\Activity;
use App\Models\AiUsage;
use App\Models\Event;
use App\Models\Search;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the scout for events matching a search and saves what it finds. A scan runs near home first and then once in
 * each of the other places the user would go, one place at a time, so each run skips what the ones before it found.
 */
class RunSearch implements ShouldQueue
{
    use Queueable;

    /**
     * How many of a run's pages Sunshine reads at once, each in a process of its own, and how long it spends reading
     * them in all. Writing out a page's events is what takes the time, 10 to 25 seconds a page, so pages are read
     * side by side to keep the run inside its timeout. A batch isn't started with less than MIN_BATCH_SECONDS left.
     */
    public const PAGES_AT_ONCE = 6;

    public const READING_TIMEOUT = 150;

    public const MIN_BATCH_SECONDS = 20;

    public int $tries = 1;

    /**
     * Room for a batch of reads (READING_TIMEOUT) and the scout's run, whose requests can take up to 300 seconds.
     */
    public int $timeout = 450;

    /**
     * @param  array{location: string, note?: ?string}|null  $place  The other place this run of a scan looks in; null looks where the search says.
     * @param  list<array{location: string, note?: ?string}>  $placesLeft  The other places the scan looks in after this one.
     */
    public function __construct(public int $searchId, public ?array $place = null, public array $placesLeft = []) {}

    public function handle(): void
    {
        $search = Search::query()->with('user')->findOrFail($this->searchId);

        $search->update(['status' => SearchStatus::Running, 'error' => null]);

        // Each week's scan starts by summing up the family's taste afresh, for every run of it to read.
        if ($search->kind === SearchKind::Discovery && $this->place === null) {
            TasteProfile::refresh($search->user);
        }

        $sources = $search->sourcesFor($this->place);
        $location = $this->place['location'] ?? null;

        // A scan keeps finding the same events day after day, often worded a little differently; only keep the new ones.
        $seen = $search->kind === SearchKind::Discovery
            ? $search->knownEventsQuery()->get()
                ->groupBy(fn (Event $event): string => $event->starts_on->toDateString())
                ->map(fn (Collection $events): array => $events->map(fn (Event $event): array => Event::sighting($event->title, $event->city))->all())
                ->all()
            : [];

        $reads = $this->read($sources, $search, $location);

        $scout = new EventScout($search->user, location: $location ?? $search->location, pagesToCheck: $sources->count());

        $response = $scout->prompt($search->brief($this->place, $this->listable($search, $reads, $seen)));

        $usage = AiUsage::record($search, $response, $location);

        $sources->each(fn (Source $source) => $source->recordCheck($response, $reads[$source->id] ?? null));

        // An event with the same title in the same city as an earlier one is that activity coming around again.
        // When two activities share both, the oldest wins.
        $activities = $search->user->activities()->get();
        $byKey = $activities->sortByDesc('id')->keyBy(fn (Activity $activity): string => Activity::key($activity->title, $activity->city));

        foreach ($response['events'] ?? [] as $found) {
            $date = $this->keeps($search, $found, $seen);

            // The address comes from pages on the web and becomes a link, so only keep a real web page.
            $url = $this->url($found['url'] ?? null);

            if ($date === null || $url === null) {
                continue;
            }

            $seen[$date->toDateString()][] = Event::sighting($found['title'], $found['city'] ?? null);

            $details = [
                'title' => $found['title'],
                'description' => $found['description'] ?? null,
                'venue' => $found['venue'] ?? null,
                'city' => $found['city'] ?? null,
                'region' => $found['region'] ?? null,
                'country' => $this->country($found['country'] ?? null),
                'category' => $found['category'] ?? null,
            ];

            // Only take the scout's word for one of this user's activities.
            $activity = (is_int($found['seen_before'] ?? null) ? $activities->find($found['seen_before']) : null)
                ?? $byKey->get(Activity::key($details['title'], $details['city']));

            if ($activity?->isAvoided()) {
                continue;
            }

            if ($activity === null) {
                $activity = $search->user->activities()->create($details);
                $byKey->put(Activity::key($activity->title, $activity->city), $activity);
            }

            $search->events()->create([
                ...$details,
                'ai_usage_id' => $usage->id,
                'activity_id' => $activity->id,
                'starts_on' => $date,
                'time' => $found['time'] ?? null,
                'cost' => $found['cost'] ?? null,
                'url' => $url,
                'reason' => $found['reason'] ?? null,
                'is_wildcard' => (bool) ($found['is_wildcard'] ?? false),
            ]);
        }

        $places = $this->placesAfter($search);

        if ($places !== []) {
            self::dispatch($search->id, $places[0], array_slice($places, 1));

            return;
        }

        $search->update(['status' => SearchStatus::Completed]);
    }

    public function failed(?Throwable $exception): void
    {
        Search::query()->whereKey($this->searchId)->update([
            'status' => SearchStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * The other places a scan still has to look in after this run: all of them after the run near home.
     *
     * @return list<array{location: string, note?: ?string}>
     */
    protected function placesAfter(Search $search): array
    {
        if ($search->kind !== SearchKind::Discovery) {
            return [];
        }

        return $this->place === null ? $search->user->otherPlaces() : $this->placesLeft;
    }

    /**
     * Sunshine's own reads of a run's pages, by source id, PAGES_AT_ONCE at a time, until READING_TIMEOUT is up. A
     * page with one address for each day is read once for each of the run's days (Search::daysToRead()), after the
     * other pages, so its last days are what's left out when time runs short. Each process leaves its read in the
     * cache rather than handing it back: Concurrency reads a process's result from what it prints, which broke on the
     * server, so a read survives whatever else gets printed or goes wrong in the hand back. Anything a read itself
     * prints is kept out of the way and logged. A page whose read doesn't arrive is left to the scout's fetch, as is
     * every page when reading pages is turned off in config.
     *
     * @param  Collection<int, Source>  $sources
     * @return array<int, PageRead>
     */
    protected function read(Collection $sources, Search $search, ?string $location): array
    {
        if (! config('sunshine.pages.read')) {
            return [];
        }

        $run = (string) Str::uuid();
        $days = $search->daysToRead();
        $pages = [];

        foreach ($sources->reject(fn (Source $source): bool => $source->isDated()) as $source) {
            $pages[] = ['key' => (string) $source->id, 'source' => $source, 'day' => null];
        }

        foreach ($sources->filter(fn (Source $source): bool => $source->isDated()) as $source) {
            foreach ($days as $day) {
                $pages[] = ['key' => "{$source->id}:{$day->toDateString()}", 'source' => $source, 'day' => $day];
            }
        }

        $deadline = now()->addSeconds(self::READING_TIMEOUT);

        foreach (array_chunk($pages, self::PAGES_AT_ONCE) as $batch) {
            $timeLeft = (int) now()->diffInSeconds($deadline);

            if ($timeLeft < self::MIN_BATCH_SECONDS) {
                break;
            }

            // Each read is serialized for its process, which can't tell apart closures nested on one line.
            $tasks = [];

            foreach ($batch as ['key' => $key, 'source' => $source, 'day' => $day]) {
                $tasks[$key] = function () use ($key, $source, $day, $search, $location, $run): void {
                    ob_start();

                    try {
                        Cache::put("page-read:{$run}:{$key}", PageRead::of($source, $search, $location, $day)->toArray(), now()->addHour());
                    } finally {
                        $printed = (string) ob_get_clean();

                        if ($printed !== '') {
                            Log::warning('A page read printed output.', ['source_id' => $source->id, 'output' => Str::limit($printed, 2_000)]);
                        }
                    }
                };
            }

            try {
                Concurrency::run($tasks, $timeLeft);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $arrived = function (string $key) use ($run): ?PageRead {
            $read = Cache::pull("page-read:{$run}:{$key}");

            return is_array($read) ? PageRead::fromArray($read) : null;
        };

        return $sources
            ->mapWithKeys(fn (Source $source): array => [$source->id => $source->isDated()
                ? $this->readByDay($source, $days, $arrived)
                : $arrived((string) $source->id)])
            ->filter()
            ->all();
    }

    /**
     * A page with one address for each day, read for every day whose read arrived.
     *
     * @param  list<CarbonImmutable>  $days
     * @param  Closure(string): ?PageRead  $arrived
     */
    protected function readByDay(Source $source, array $days, Closure $arrived): PageRead
    {
        $reads = [];

        foreach ($days as $day) {
            $reads[$day->toDateString()] = $arrived("{$source->id}:{$day->toDateString()}");
        }

        return PageRead::ofDays(array_filter($reads), count($days));
    }

    /**
     * The events on each page Sunshine read that the run could keep, by source id, for the scout to choose from.
     *
     * @param  array<int, PageRead>  $reads
     * @param  array<string, list<array{title: string, city: string, words: list<string>}>>  $seen
     * @return array<int, list<array<string, mixed>>>
     */
    protected function listable(Search $search, array $reads, array $seen): array
    {
        return collect($reads)
            ->filter(fn (PageRead $read): bool => $read->read)
            ->map(fn (PageRead $read): array => array_values(array_filter(
                $read->events,
                fn (array $event): bool => is_string($event['title'] ?? null) && $this->keeps($search, $event, $seen) !== null,
            )))
            ->all();
    }

    /**
     * The date of an event found for a search, or null when it is left out: undated or outside the search's dates,
     * or, on a scan, at a time the user isn't free or already found (Event::isRepeat()).
     *
     * @param  array<string, mixed>  $found
     * @param  array<string, list<array{title: string, city: string, words: list<string>}>>  $seen
     */
    protected function keeps(Search $search, array $found, array $seen): ?CarbonImmutable
    {
        $date = $this->date($found['date'] ?? null);

        if ($date === null || $date->lt($search->starts_on) || $date->gt($search->ends_on)) {
            return null;
        }

        if ($search->kind !== SearchKind::Discovery) {
            return $date;
        }

        if (! $search->user->isFreeAt($date, $this->clock($found['start_time'] ?? null), $this->clock($found['end_time'] ?? null))) {
            return null;
        }

        return Event::isRepeat(Event::sighting($found['title'], $found['city'] ?? null), $seen[$date->toDateString()] ?? []) ? null : $date;
    }

    protected function country(?string $value): ?string
    {
        return preg_match('/^[a-z]{2}$/i', (string) $value) ? strtoupper($value) : null;
    }

    protected function url(?string $value): ?string
    {
        $isWebPage = filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);

        return $isWebPage ? $value : null;
    }

    /**
     * A time of day as 24-hour "HH:MM", or null when it isn't one.
     */
    protected function clock(?string $value): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $value), $parts) || $parts[1] > 23 || $parts[2] > 59) {
            return null;
        }

        return sprintf('%02d:%s', $parts[1], $parts[2]);
    }

    protected function date(?string $value): ?CarbonImmutable
    {
        if (blank($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
