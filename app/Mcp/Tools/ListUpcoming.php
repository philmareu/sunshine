<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List what the family sees on Upcoming: every event their scans found from today on, across all scans, by date, with how many there are in each city. Each event says how the family marked it and whether they can\'t make it (Upcoming hides those by default), names the scan that found it and its activity, so the same thing found twice under different titles shows up side by side. Give a city to list only its events.')]
#[IsReadOnly]
class ListUpcoming extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['city' => ['nullable', 'string', 'max:255']]);

        /** @var User $user */
        $user = $request->user();

        $events = $user->discoveredEvents()
            ->whereDate('events.starts_on', '>=', $user->today())
            ->orderBy('events.starts_on')
            ->orderBy('events.city')
            ->orderBy('events.title')
            ->get();

        $city = $validated['city'] ?? null;

        return Response::json([
            'today' => $user->today()->toDateString(),
            'by_city' => $events->countBy(fn (Event $event): string => $event->city ?? 'No city')->sortDesc()->all(),
            'events' => $events
                ->filter(fn (Event $event): bool => blank($city) || strcasecmp((string) $event->city, $city) === 0)
                ->map(fn (Event $event): array => [
                    'id' => $event->id,
                    'date' => $event->starts_on->toDateString(),
                    'time' => $event->time,
                    'title' => $event->title,
                    'venue' => $event->venue,
                    'city' => $event->city,
                    'scan' => $event->search_id,
                    'activity_id' => $event->activity_id,
                    'wildcard' => $event->is_wildcard,
                    'marked' => $event->status?->getLabel(),
                    'cant_make_it' => $event->hidden_at !== null,
                    'url' => $event->url,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('Only list events in this town or city, as by_city names it.'),
        ];
    }
}
