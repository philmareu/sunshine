<?php

namespace App\Mcp\Tools;

use App\Enums\SearchKind;
use App\Models\Search;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List this family\'s most recent ad hoc searches, newest first, with where, when, how each is going and how many events it found. Weekly scans aren\'t included; get-scan has those.')]
#[IsReadOnly]
class ListSearches extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $searches = $user->searches()
            ->where('kind', SearchKind::AdHoc)
            ->withCount('events')
            ->latest('id')
            ->limit(20)
            ->get();

        return Response::json($searches->map(fn (Search $search): array => [
            'id' => $search->id,
            'location' => $search->location,
            'starts_on' => $search->starts_on->toDateString(),
            'ends_on' => $search->ends_on->toDateString(),
            'direction' => $search->direction,
            'status' => $search->status->value,
            'events' => $search->events_count,
        ])->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
