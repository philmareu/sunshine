<?php

namespace App\Models;

use App\Enums\EventStatus;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Something the family could do, such as a farmers market, a festival or a team's home games. Each event is one
 * time it came up, so the activity gathers every date it was found on and how each visit went. An avoided
 * activity is never suggested again.
 */
#[Fillable(['user_id', 'title', 'description', 'venue', 'city', 'region', 'country', 'category', 'avoided_at', 'avoid_reason'])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'avoided_at' => 'datetime',
        ];
    }

    /**
     * What makes two events the same activity when the scout doesn't say: the same title in the same city,
     * ignoring case and spacing.
     */
    public static function key(string $title, ?string $city): string
    {
        return Str::of($title)->lower()->squish().'|'.Str::of((string) $city)->lower()->squish();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every time it came up.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * The times they went.
     *
     * @return HasMany<Event, $this>
     */
    public function visits(): HasMany
    {
        return $this->events()->where('status', EventStatus::Went);
    }

    /**
     * @return HasMany<ActivityNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ActivityNote::class);
    }

    /**
     * @return HasMany<ActivityPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ActivityPhoto::class);
    }

    /**
     * What to bring or do every time, in order.
     *
     * @return HasMany<ChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Activities with nothing left in them: no events, and nothing the family added.
     *
     * @param  Builder<Activity>  $query
     */
    #[Scope]
    protected function unused(Builder $query): void
    {
        $query->doesntHave('events')
            ->doesntHave('notes')
            ->doesntHave('photos')
            ->doesntHave('checklistItems');
    }

    /**
     * Fold other activities into this one, when they turn out to be the same thing. Their dates, notes, photos and
     * checklist move here, details this one lacks are filled in from theirs, and they go. If one of them was
     * avoided, this one is too.
     *
     * @param  iterable<Activity>  $others
     */
    public function absorb(iterable $others): void
    {
        DB::transaction(function () use ($others): void {
            foreach ($others as $other) {
                if ($other->is($this) || $other->user_id !== $this->user_id) {
                    continue;
                }

                $other->events()->update(['activity_id' => $this->id]);
                $other->notes()->update(['activity_id' => $this->id]);
                $other->photos()->update(['activity_id' => $this->id]);

                $sort = $this->checklistItems()->max('sort') ?? 0;

                foreach ($other->checklistItems as $item) {
                    $item->update(['activity_id' => $this->id, 'sort' => ++$sort]);
                }

                foreach (['description', 'venue', 'city', 'region', 'country', 'category'] as $attribute) {
                    $this->{$attribute} ??= $other->{$attribute};
                }

                if ($other->isAvoided() && ! $this->isAvoided()) {
                    $this->fill($other->only(['avoided_at', 'avoid_reason']));
                }

                $other->delete();
            }

            $this->save();
        });
    }

    public function isAvoided(): bool
    {
        return $this->avoided_at !== null;
    }

    /**
     * Never suggest it again.
     */
    public function avoid(?string $reason): void
    {
        $this->update(['avoided_at' => now(), 'avoid_reason' => $reason]);
    }

    public function stopAvoiding(): void
    {
        $this->update(['avoided_at' => null, 'avoid_reason' => null]);
    }

    /**
     * The venue and the area it is in, e.g. "Grand Theater · Riverton, Oregon".
     */
    public function place(): ?string
    {
        return collect([$this->venue, collect([$this->city, $this->region])->filter()->implode(', ')])->filter()->implode(' · ') ?: null;
    }
}
