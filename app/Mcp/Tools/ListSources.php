<?php

namespace App\Mcp\Tools;

use App\Ai\Agents\EventScout;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the pages this family watches, each with the place whose run of every scan opens it (null for the run near home), whether Sunshine shows it in its browser first and how the last check went: whether the page was read, and by whom (Sunshine reads pages itself first and leaves the ones it can\'t to the scout\'s fetch); how many events in range Sunshine read on it and its reader\'s note, or why Sunshine couldn\'t read it; and the scout\'s note on what it took from it. pages_per_run is how many pages the scout itself can fetch in a run. Check here before adding a source so nothing is added twice.')]
#[IsReadOnly]
class ListSources extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $places = array_column($user->otherPlaces(), 'location');

        return Response::json([
            'pages_per_run' => EventScout::MAX_PAGES_FETCHED,
            'sources' => $user->watchedSources()->oldest('id')->get()->map(fn (Source $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'url' => $source->url,
                'place' => $source->runsIn($places),
                'browser' => $source->needs_browser,
                'last_check' => self::lastCheck($source, $user),
            ])->all(),
        ]);
    }

    /**
     * @return array{at: string, page_read: bool, read_by: ?string, events_read: ?int, read_note: ?string, note: ?string}|null
     */
    public static function lastCheck(Source $source, User $user): ?array
    {
        return $source->checked_at ? [
            'at' => $source->checked_at->timezone($user->timezone)->toDateTimeString(),
            'page_read' => $source->page_read,
            'read_by' => match (true) {
                $source->events_read !== null => 'sunshine',
                $source->page_read => 'scout',
                default => null,
            },
            'events_read' => $source->events_read,
            'read_note' => $source->read_note,
            'note' => $source->check_note,
        ] : null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
