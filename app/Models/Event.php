<?php

namespace App\Models;

use App\Enums\EventStatus;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Something to do on a date, found by a search: one time an activity came up. The family marks it as it goes:
 * interested or not for us beforehand, then went (with a rating and a retrospective) or didn't go afterwards.
 * Beforehand they can also say they can't make it (hidden_at), which takes it off Upcoming and Plans but leaves its
 * mark as it is: it's about their calendar, not what they like, so the scout learns nothing from it.
 */
#[Fillable([
    'search_id', 'ai_usage_id', 'activity_id', 'title', 'description', 'starts_on', 'time', 'venue', 'city', 'region', 'country', 'category', 'cost', 'url',
    'reason', 'is_wildcard', 'status', 'note', 'rating', 'retrospective', 'checked_items', 'marked_at', 'hidden_at',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * Words that don't tell one event's title from another's.
     */
    public const FILLER_WORDS = ['a', 'an', 'and', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

    /**
     * An event's title and city, written the way repeats are compared: the title as it is and as its words
     * (lowercase, without punctuation, years or filler words).
     *
     * @return array{title: string, city: string, words: list<string>}
     */
    public static function sighting(string $title, ?string $city): array
    {
        $words = Str::of($title)->ascii()->lower()->remove("'")->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->explode(' ')
            ->reject(fn (string $word): bool => $word === '' || in_array($word, self::FILLER_WORDS, true) || preg_match('/^(19|20)\d\d$/', $word) === 1)
            ->unique()
            ->values()
            ->all();

        return [
            'title' => Str::of($title)->lower()->squish()->toString(),
            'city' => Str::of((string) $city)->lower()->squish()->toString(),
            'words' => $words,
        ];
    }

    /**
     * Whether an event was already found on the same date: with the same title, or in the same city with a title
     * whose words all appear in the other's, as when a later run words it differently ("Nature Quest" and "Nature Quest
     * (outdoor scavenger hunt)"). One word in common isn't enough, so "Storytime" leaves "Toddler Storytime" alone.
     *
     * @param  array{title: string, city: string, words: list<string>}  $sighting
     * @param  list<array{title: string, city: string, words: list<string>}>  $sameDay
     */
    public static function isRepeat(array $sighting, array $sameDay): bool
    {
        foreach ($sameDay as $earlier) {
            if ($earlier['title'] === $sighting['title']) {
                return true;
            }

            if ($earlier['city'] !== $sighting['city'] || $earlier['words'] === [] || $sighting['words'] === []) {
                continue;
            }

            [$fewer, $more] = count($earlier['words']) <= count($sighting['words'])
                ? [$earlier['words'], $sighting['words']]
                : [$sighting['words'], $earlier['words']];

            if (array_diff($fewer, $more) === [] && (count($fewer) >= 2 || count($fewer) === count($more))) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'is_wildcard' => 'boolean',
            'status' => EventStatus::class,
            'rating' => 'integer',
            'checked_items' => 'array',
            'marked_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Search, $this>
     */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * The AI run that found it; null for events found before runs were recorded on them.
     *
     * @return BelongsTo<AiUsage, $this>
     */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(AiUsage::class, 'ai_usage_id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Photos from this visit.
     *
     * @return HasMany<ActivityPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ActivityPhoto::class);
    }

    /**
     * The last time they went to this before this date, when it has come around again.
     */
    public function previousVisit(): ?Event
    {
        return $this->activity->visits
            ->sortByDesc('starts_on')
            ->first(fn (Event $visit): bool => $visit->isBefore($this->starts_on));
    }

    /**
     * The family's other events still to come that look like the same thing and aren't marked yet: other dates of
     * its activity, and events in the same city with the same title but for its numbers, such as another level of a
     * class ("Gymnastics 1" and "Gymnastics 2"). Marking this one "Not for us" marks them too, unless the family unticks
     * them.
     *
     * @return Collection<int, Event>
     */
    public function relatedToCome(): Collection
    {
        $user = $this->activity->user;
        $sighting = fn (Event $event): array => self::sighting(preg_replace('/\d+/', ' ', $event->title), $event->city);

        return $user->events()
            ->whereKeyNot($this->id)
            ->whereNull('events.status')
            ->whereDate('events.starts_on', '>=', $user->today())
            ->where(fn (Builder $query) => $query->where('events.activity_id', $this->activity_id)->orWhere('events.city', $this->city))
            ->orderBy('events.starts_on')
            ->get()
            ->filter(fn (Event $event): bool => $event->activity_id === $this->activity_id || self::isRepeat($sighting($event), [$sighting($this)]))
            ->values();
    }

    /**
     * Move this date to another activity, or to a new one of its own when it was wrongly taken for something
     * else. Its photos go with it, and its checklist starts fresh. The activity it leaves goes if nothing is left in it.
     */
    public function moveTo(?Activity $activity, ?string $title = null): void
    {
        DB::transaction(function () use ($activity, $title): void {
            $from = $this->activity;

            $activity ??= $from->user->activities()->create([
                ...$this->only(['description', 'venue', 'city', 'region', 'country', 'category']),
                'title' => $title ?? $this->title,
            ]);

            $this->update(['activity_id' => $activity->id, 'checked_items' => null]);
            $this->photos()->update(['activity_id' => $activity->id]);
            $this->setRelation('activity', $activity);

            Activity::query()->whereKey($from->id)->unused()->delete();
        });
    }

    /**
     * How many of its activity's checklist items are ticked off for this visit.
     */
    public function checkedCount(): int
    {
        return $this->activity->checklistItems->whereIn('id', $this->checked_items ?? [])->count();
    }

    /**
     * Whether the event's date is before this day. Event dates have no time zone, so compare them as dates.
     */
    public function isBefore(CarbonInterface $day): bool
    {
        return $this->starts_on->toDateString() < $day->toDateString();
    }
}
