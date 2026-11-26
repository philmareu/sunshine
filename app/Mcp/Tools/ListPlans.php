<?php

namespace App\Mcp\Tools;

use App\Enums\EventStatus;
use App\Models\ActivityNote;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List what the family plans to go to: the events they marked Interested, from any scan or ad hoc search, from today on, soonest first, as Sunshine\'s Plans page shows them (leaving out those they can\'t make). Gives the time now where they live, so "what\'s on in the next few hours" can be answered from each event\'s date and time. Each plan comes with their notes on that activity, its checklist for this visit (what to bring or do, and what\'s ticked off) and how their last visit went. Ask for one day for just today.')]
#[IsReadOnly]
class ListPlans extends Tool
{
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 60;

    /**
     * The most recent notes given on each activity.
     */
    public const NOTES = 3;

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS]]);

        /** @var User $user */
        $user = $request->user();
        $today = $user->today();
        $days = $validated['days'] ?? self::DEFAULT_DAYS;

        $plans = $user->events()
            ->where('events.status', EventStatus::Interested)
            ->whereNull('events.hidden_at')
            ->whereDate('events.starts_on', '>=', $today)
            ->whereDate('events.starts_on', '<', $today->addDays($days))
            ->with([
                'activity.notes' => fn (HasMany $notes): HasMany => $notes->latest('id'),
                'activity.checklistItems',
                'activity.visits',
            ])
            ->orderBy('events.starts_on')
            ->orderBy('events.id')
            ->get();

        return Response::json([
            'now' => now($user->timezone)->format('l, Y-m-d H:i'),
            'timezone' => $user->timezone,
            'through' => $today->addDays($days - 1)->toDateString(),
            'plans' => $plans->map(fn (Event $event): array => $this->plan($event))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(Event $event): array
    {
        $checked = array_map(intval(...), $event->checked_items ?? []);
        $lastVisit = $event->activity === null ? null : $event->previousVisit();

        return [
            'id' => $event->id,
            'date' => $event->starts_on->format('l, Y-m-d'),
            'time' => $event->time,
            'title' => $event->title,
            'venue' => $event->venue,
            'city' => $event->city,
            'cost' => $event->cost,
            'description' => $event->description,
            'url' => $event->url,
            'activity_id' => $event->activity_id,
            'notes' => $event->activity?->notes->take(self::NOTES)->map(fn (ActivityNote $note): string => $note->body)->values()->all() ?? [],
            'checklist' => $event->activity?->checklistItems->map(fn (ChecklistItem $item): array => [
                'item' => $item->label,
                'done' => in_array($item->id, $checked, true),
            ])->values()->all() ?? [],
            'last_visit' => $lastVisit === null ? null : [
                'date' => $lastVisit->starts_on->toDateString(),
                'rating' => $lastVisit->rating,
                'retrospective' => $lastVisit->retrospective,
            ],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('How many days to list, starting today: 1 for just today. Defaults to '.self::DEFAULT_DAYS.', at most '.self::MAX_DAYS.'.'),
        ];
    }
}
