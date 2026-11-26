<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Stop watching a page, by its id from list-sources. Its last check note goes with it.')]
#[IsDestructive]
class RemoveSource extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        /** @var User $user */
        $user = $request->user();
        $source = $user->watchedSources()->find($validated['id']);

        if ($source === null) {
            return Response::error('No watched page has that id. Call list-sources to see them.');
        }

        $source->delete();

        return Response::text('Stopped watching '.($source->name ?? $source->url).'.');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The source\'s id, from list-sources.')->required(),
        ];
    }
}
