<?php

namespace App\Ai;

use App\Ai\Agents\TasteProfiler;
use App\Enums\EventStatus;
use App\Models\AiUsage;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * A short summary of what the family says yes to and what they skip, by kind of thing and by day and time, written
 * about once a week by TasteProfiler from everything they marked and the suggestions they let pass. The scout reads
 * it in place of working their taste out from their marks one by one.
 */
class TasteProfile
{
    /**
     * How old a profile gets before the next scan writes a new one: a little under a week, so each weekly scan does.
     */
    public const MAX_AGE_DAYS = 6;

    /**
     * How long a suggestion goes unmarked before it counts as a soft no.
     */
    public const SOFT_NO_AFTER_DAYS = 14;

    /**
     * The most events of each kind the profiler reads, newest first: ones they marked, and ones they left unmarked.
     */
    public const EVENTS_READ = 200;

    public static function isStale(User $user): bool
    {
        return $user->taste_profiled_at === null || $user->taste_profiled_at->lte(now()->subDays(self::MAX_AGE_DAYS));
    }

    /**
     * Write a new profile when the old one is stale and they have marked something. If writing it fails, the old one
     * stays, so a scan never fails over it.
     */
    public static function refresh(User $user): void
    {
        if (! static::isStale($user)) {
            return;
        }

        $marked = static::marked($user);

        if ($marked->isEmpty()) {
            return;
        }

        $lines = ['Suggestions they marked, newest first:'];

        foreach ($marked as $event) {
            $lines[] = static::line($event).' — '.$event->status->getLabel()
                .(match ($event->status) {
                    EventStatus::Went => ($event->rating ? ", rated {$event->rating}/5" : '').(filled($event->retrospective) ? ': '.Str::squish($event->retrospective) : ''),
                    EventStatus::NotForUs => filled($event->note) ? ': '.Str::squish($event->note) : '',
                    default => '',
                });
        }

        $unmarked = static::unmarked($user);

        if ($unmarked->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Suggestions they left unmarked for '.self::SOFT_NO_AFTER_DAYS.' days or more, newest first:';

            foreach ($unmarked as $event) {
                $lines[] = static::line($event);
            }
        }

        try {
            $reply = (new TasteProfiler)->prompt(implode("\n", $lines));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        AiUsage::recordTasteProfile($user, $reply);

        $profile = trim((string) ($reply['profile'] ?? ''));

        if ($profile !== '') {
            $user->forceFill(['taste_profile' => $profile, 'taste_profiled_at' => now()])->save();
        }
    }

    /**
     * One event as the profiler reads it: when, what kind, where and what.
     */
    protected static function line(Event $event): string
    {
        return '- '.collect([
            $event->starts_on->format('D Y-m-d'),
            $event->time,
            $event->category,
            $event->city,
            '"'.$event->title.'"'.($event->is_wildcard ? ' (wildcard)' : ''),
        ])->filter()->implode(' · ');
    }

    /**
     * What they marked, but not the things they didn't get to: Didn't go says nothing about their taste.
     *
     * @return Collection<int, Event>
     */
    protected static function marked(User $user): Collection
    {
        return $user->events()
            ->whereIn('events.status', [EventStatus::Interested, EventStatus::NotForUs, EventStatus::Went])
            ->latest('events.marked_at')
            ->limit(self::EVENTS_READ)
            ->get();
    }

    /**
     * Suggestions they let pass: never marked, not set aside as a date they couldn't make, and found long enough ago.
     *
     * @return Collection<int, Event>
     */
    protected static function unmarked(User $user): Collection
    {
        return $user->events()
            ->whereNull('events.status')
            ->whereNull('events.hidden_at')
            ->where('events.created_at', '<=', now()->subDays(self::SOFT_NO_AFTER_DAYS))
            ->latest('events.created_at')
            ->limit(self::EVENTS_READ)
            ->get();
    }
}
