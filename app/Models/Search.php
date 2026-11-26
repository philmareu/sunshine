<?php

namespace App\Models;

use App\Ai\Agents\EventScout;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\WeekDay;
use Closure;
use Database\Factories\SearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;

#[Fillable(['user_id', 'kind', 'location', 'starts_on', 'ends_on', 'direction', 'status', 'error'])]
class Search extends Model
{
    /** @use HasFactory<SearchFactory> */
    use HasFactory;

    /**
     * How many already-found events a scan lists so the scout skips them.
     */
    public const KNOWN_EVENTS_LIMIT = 100;

    protected $attributes = [
        'kind' => SearchKind::AdHoc,
        'status' => SearchStatus::Pending,
    ];

    protected function casts(): array
    {
        return [
            'kind' => SearchKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => SearchStatus::class,
        ];
    }

    /**
     * A search holding events the family marked is their history, so it is kept. Activities that only came up
     * in a deleted search go with it, unless the family added notes, photos or a checklist to them.
     */
    protected static function booted(): void
    {
        static::deleting(fn (Search $search): bool => ! $search->hasMarkedEvents());
        static::deleted(fn (Search $search) => $search->user->activities()->unused()->delete());
    }

    /**
     * Start a scan of the user's home area for the coming days.
     */
    public static function discoveryFor(User $user): self
    {
        return $user->searches()->create([
            'kind' => SearchKind::Discovery,
            'location' => $user->home_location,
            'starts_on' => $user->today()->toDateString(),
            'ends_on' => $user->today()->addDays(config('sunshine.discovery.days_ahead'))->toDateString(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * The pages only this search opens. Scans open the user's watched pages instead.
     *
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * The pages a run of this search opens: an ad hoc search's own, or for a scan, the watched pages of the place
     * the run is in.
     *
     * @param  array{location: string, note?: ?string}|null  $place
     * @return Collection<int, Source>
     */
    public function sourcesFor(?array $place = null): Collection
    {
        if ($this->kind === SearchKind::AdHoc) {
            return $this->sources()->oldest('id')->get();
        }

        $places = array_column($this->user->otherPlaces(), 'location');

        return $this->user->watchedSources()->oldest('id')->get()
            ->filter(fn (Source $source): bool => $source->runsIn($places) === ($place['location'] ?? null))
            ->values();
    }

    /**
     * The days a run reads a page with one address for each day: every day of an ad hoc search, or each day of a scan
     * they can go out, at most Source::MAX_DAYS of them.
     *
     * @return list<CarbonImmutable>
     */
    public function daysToRead(): array
    {
        $days = [];

        for ($day = $this->starts_on->toImmutable(); $day->lte($this->ends_on) && count($days) < Source::MAX_DAYS; $day = $day->addDay()) {
            if ($this->kind !== SearchKind::Discovery || $this->user->isFreeAt($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * Whether the family marked any of its events: interested, not for us, went or didn't go.
     */
    public function hasMarkedEvents(): bool
    {
        return $this->events()->whereNotNull('status')->exists();
    }

    /**
     * What this search asks the scout to find, near where the search says or, for a scan, in one of the other
     * places the user would go, along with the pages that run opens: the events on the ones Sunshine read itself,
     * and the rest for the scout to fetch.
     *
     * @param  array{location: string, note?: ?string}|null  $place
     * @param  array<int, list<array<string, mixed>>>  $listed  The events to list from each page Sunshine read, by source id.
     */
    public function brief(?array $place = null, array $listed = []): string
    {
        $brief = sprintf(
            'Find things to do in or near %s between %s and %s.',
            $place['location'] ?? $this->location,
            $this->starts_on->toDateString(),
            $this->ends_on->toDateString(),
        );

        if (filled($this->direction)) {
            $brief .= "\n\nFor this search specifically: ".$this->direction;
        }

        if (filled($place['note'] ?? null)) {
            $brief .= "\n\nWhen they'd go here: ".$place['note'];
        }

        if ($this->kind === SearchKind::Discovery) {
            $brief .= $this->otherRuns($place);
            $brief .= $this->freeTimes();
        }

        $brief .= $this->pagesToCheck($place, $listed);

        if ($this->kind === SearchKind::Discovery) {
            $brief .= $this->knownEvents();
        }

        return $brief;
    }

    /**
     * The events earlier scans already found in this search's date range.
     *
     * @return HasManyThrough<Event, Search, User>
     */
    public function knownEventsQuery(): HasManyThrough
    {
        return $this->user->discoveredEvents()
            ->whereDate('events.starts_on', '>=', $this->starts_on)
            ->whereDate('events.starts_on', '<=', $this->ends_on);
    }

    /**
     * The places a scan's other runs search, so this run leaves those places to them instead of drifting there.
     *
     * @param  array{location: string, note?: ?string}|null  $place
     */
    protected function otherRuns(?array $place): string
    {
        $here = $place['location'] ?? $this->location;

        $elsewhere = collect([$this->location, ...array_column($this->user->otherPlaces(), 'location')])
            ->reject(fn (string $location): bool => $location === $here)
            ->unique()
            ->values()
            ->all();

        if ($elsewhere === []) {
            return '';
        }

        return "\n\nThis scan searches ".Arr::join($elsewhere, ', ', ' and ')." in runs of their own, so stay near {$here} and leave events in those places to them.";
    }

    /**
     * When the user can go out, as lines like "Any time on Saturday and Sunday"; none when they haven't said.
     *
     * @return list<string>
     */
    public function freeTimeLines(): array
    {
        $allDay = $this->dayNames($this->user->free_all_day ?? []);
        $evenings = array_diff($this->dayNames($this->user->free_evenings ?? []), $allDay);

        return array_values(array_filter([
            $allDay ? 'Any time on '.Arr::join($allDay, ', ', ' and ') : null,
            $evenings ? 'In the evening, from about 5 PM, on '.Arr::join($evenings, ', ', ' and ') : null,
        ]));
    }

    protected function freeTimes(): string
    {
        $times = $this->freeTimeLines();

        if ($times === []) {
            return '';
        }

        return "\n\nThey can only go out at these times, so only include events they could go to then. For something that runs over several days, give a date that works:\n"
            .collect($times)->map(fn (string $time): string => "- {$time}")->implode("\n");
    }

    /**
     * Day names, Monday first, for days of the week numbered 0 (Sunday) to 6.
     *
     * @param  array<int|string>  $days
     * @return list<string>
     */
    protected function dayNames(array $days): array
    {
        return collect($days)
            ->map(intval(...))
            ->sortBy(fn (int $day): int => ($day + 6) % 7)
            ->map(fn (int $day): string => WeekDay::from($day)->name)
            ->values()
            ->all();
    }

    /**
     * @param  array{location: string, note?: ?string}|null  $place
     * @param  array<int, list<array<string, mixed>>>  $listed
     */
    protected function pagesToCheck(?array $place, array $listed): string
    {
        $sources = $this->sourcesFor($place);

        if ($sources->isEmpty()) {
            return '';
        }

        [$read, $toFetch] = $sources->partition(fn (Source $source): bool => array_key_exists($source->id, $listed));
        $page = fn (Source $source, ?CarbonInterface $day = null): string => ($source->name ? "{$source->name}: " : '').($day ? $source->urlFor($day) : $source->url);
        $text = '';

        if ($read->isNotEmpty()) {
            $text .= "\n\nThey asked you to check these pages, and they have been read for you, so don't fetch them. Here are the events on each in the date range"
                .($this->kind === SearchKind::Discovery ? ' that are new to them and at times they can go out' : '')
                .', one per line: date, time, title, where, cost and link, then what it is. Include the ones they would enjoy, with the event\'s link as its url, or else the page\'s. For each page, add a note to sources saying what you included and why you left anything out:';

            foreach ($read as $source) {
                $events = $listed[$source->id];

                $text .= "\n\n".$page($source).($source->isDated() ? ' (a page for each day, read for you day by day)' : '')."\n"
                    .($events === [] ? '(none)' : collect($events)->map($this->eventLine(...))->implode("\n"));
            }
        }

        if ($toFetch->isNotEmpty()) {
            $text .= "\n\nThey asked you to check ".($read->isNotEmpty() ? 'these pages too' : 'these pages').'. Fetch each one and include its events in the date range that they could go to. For each page, add a note to sources saying what you found there and why you left anything out:'."\n"
                .$toFetch->flatMap(fn (Source $source): array => $this->addressesToFetch($source, $page))->implode("\n");
        }

        // A run whose pages were all read for it used to answer from them alone, making no web searches at all.
        return $text
            ."\n\nThese pages are where to start, not the whole search: they cover only part of what's on. Before you answer, use your "
            .EventScout::webSearchesFor($sources->count())
            .' web searches to find events they don\'t list, even if the pages already give you plenty to choose from.';
    }

    /**
     * The lines of the brief asking the scout to fetch a page: its address, or for a page with one address for each
     * day, its addresses for the first few days.
     *
     * @param  Closure(Source, ?CarbonInterface): string  $page
     * @return list<string>
     */
    protected function addressesToFetch(Source $source, Closure $page): array
    {
        if (! $source->isDated()) {
            return ['- '.$page($source)];
        }

        return array_map(
            fn (CarbonImmutable $day): string => '- '.$page($source, $day).' ('.$day->format('l, M j').')',
            array_slice($this->daysToRead(), 0, Source::DAYS_TO_FETCH),
        );
    }

    /**
     * One event read off a page, as a line of the brief.
     *
     * @param  array<string, mixed>  $event
     */
    protected function eventLine(array $event): string
    {
        $time = filled($event['start_time'] ?? null)
            ? $event['start_time'].(filled($event['end_time'] ?? null) ? '–'.$event['end_time'] : '')
            : null;

        $fields = array_filter([
            $event['date'] ?? null,
            $time,
            $event['title'] ?? null,
            implode(', ', array_filter([$event['venue'] ?? null, $event['city'] ?? null], filled(...))),
            $event['cost'] ?? null,
            $event['url'] ?? null,
        ], filled(...));

        return '- '.implode(' · ', $fields).(filled($event['description'] ?? null) ? ' — '.$event['description'] : '');
    }

    protected function knownEvents(): string
    {
        $known = $this->knownEventsQuery()->orderBy('events.starts_on')->limit(self::KNOWN_EVENTS_LIMIT)->get();

        if ($known->isEmpty()) {
            return '';
        }

        return "\n\nThey already know about these, so only return events that are not on this list:\n"
            .$known->map(fn (Event $event): string => "- {$event->starts_on->toDateString()} {$event->title}")->implode("\n");
    }
}
