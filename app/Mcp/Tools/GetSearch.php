<?php

namespace App\Mcp\Tools;

use App\Enums\SearchKind;
use App\Models\Event;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get an ad hoc search by id: how it is going (pending, running, completed or failed, with the error), the pages it was asked to check with what the scout found on each, and the events it found, soonest first, each with why the scout picked it and how the family marked it.')]
#[IsReadOnly]
class GetSearch extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        /** @var User $user */
        $user = $request->user();
        $search = $user->searches()->where('kind', SearchKind::AdHoc)->find($validated['id']);

        if ($search === null) {
            return Response::error('No search has that id. Call list-searches to see them.');
        }

        return Response::json([
            'id' => $search->id,
            'location' => $search->location,
            'starts_on' => $search->starts_on->toDateString(),
            'ends_on' => $search->ends_on->toDateString(),
            'direction' => $search->direction,
            'status' => $search->status->value,
            'error' => $search->error,
            'sources' => $search->sources()->oldest('id')->get()->map(fn (Source $source): array => [
                'name' => $source->name,
                'url' => $source->url,
                'last_check' => ListSources::lastCheck($source, $user),
            ])->all(),
            'events' => $search->events()->orderBy('starts_on')->orderBy('id')->get()->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'date' => $event->starts_on->toDateString(),
                'time' => $event->time,
                'venue' => $event->venue,
                'city' => $event->city,
                'region' => $event->region,
                'category' => $event->category,
                'cost' => $event->cost,
                'description' => $event->description,
                'reason' => $event->reason,
                'wildcard' => $event->is_wildcard,
                'url' => $event->url,
                'marked' => $event->status?->getLabel(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The search\'s id, from create-search or list-searches.')->required(),
        ];
    }
}
