<?php

namespace App\Mcp\Tools;

use App\Enums\SearchKind;
use App\Models\AiUsage;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Enumerable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get a weekly scan, the latest unless given an id: how it went, and each run in turn (near home, then each other place) with what Sunshine\'s own reads of its pages cost (a page with one address for each day is read once a day), its web searches, pages fetched, tokens and cost, and the new events it kept, each with why the scout picked it and how the family marked it. The scan\'s cost includes the reads. Also lists their recent scans, to look at an earlier one.')]
#[IsReadOnly]
class GetScan extends Tool
{
    /**
     * How many recent scans it lists.
     */
    public const RECENT_SCANS = 10;

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['nullable', 'integer']]);

        /** @var User $user */
        $user = $request->user();

        $scan = isset($validated['id'])
            ? $user->searches()->where('kind', SearchKind::Discovery)->find($validated['id'])
            : $user->latestScan();

        if ($scan === null) {
            return Response::error(isset($validated['id']) ? 'No scan has that id. Leave it out to get the latest.' : 'There hasn\'t been a scan yet.');
        }

        $usages = $scan->usages()
            ->with(['source', 'events' => fn ($query) => $query->orderBy('starts_on')->orderBy('id')])
            ->oldest('id')
            ->get();

        // Sunshine's own reads of a run's pages come before the run, in the same place.
        [$reads, $runs] = $usages->partition(fn (AiUsage $usage): bool => $usage->source_id !== null);

        $result = [
            'id' => $scan->id,
            'location' => $scan->location,
            'starts_on' => $scan->starts_on->toDateString(),
            'ends_on' => $scan->ends_on->toDateString(),
            'started_at' => $scan->created_at->timezone($user->timezone)->toDateTimeString(),
            'status' => $scan->status->value,
            'error' => $scan->error,
            'cost' => $usages->contains(fn (AiUsage $usage): bool => $usage->cost === null) ? null : round($usages->sum('cost'), 4),
            'runs' => $runs->values()->map(fn (AiUsage $run): array => [
                'place' => $run->location,
                'model' => $run->model,
                // A page with one address for each day is read once for each day, so reads are summed by page.
                'pages_read' => $reads->where('location', $run->location)->groupBy('source_id')->values()->map(fn (Enumerable $pageReads): array => [
                    'page' => $pageReads->first()->source->name ?? $pageReads->first()->source->url,
                    'model' => $pageReads->first()->model,
                    'reads' => $pageReads->count(),
                    'input_tokens' => $pageReads->sum('input_tokens'),
                    'output_tokens' => $pageReads->sum('output_tokens'),
                    'cost' => $pageReads->contains(fn (AiUsage $read): bool => $read->cost === null) ? null : round($pageReads->sum('cost'), 4),
                ])->all(),
                'web_searches' => $run->web_searches,
                'pages_fetched' => $run->pages_fetched,
                'input_tokens' => $run->input_tokens,
                'cache_write_tokens' => $run->cache_write_tokens,
                'cache_read_tokens' => $run->cache_read_tokens,
                'output_tokens' => $run->output_tokens,
                'cost' => $run->cost === null ? null : round($run->cost, 4),
                'events' => $this->events($run->events),
            ])->all(),
        ];

        // Scans from before each event recorded the run that found it.
        $withoutRun = $scan->events()->whereNull('ai_usage_id')->orderBy('starts_on')->orderBy('id')->get();

        if ($withoutRun->isNotEmpty()) {
            $result['events_without_run'] = $this->events($withoutRun);
        }

        $result['recent_scans'] = $user->searches()
            ->where('kind', SearchKind::Discovery)
            ->withCount('events')
            ->latest('id')
            ->limit(self::RECENT_SCANS)
            ->get()
            ->map(fn (Search $recent): array => [
                'id' => $recent->id,
                'started_at' => $recent->created_at->timezone($user->timezone)->toDateTimeString(),
                'status' => $recent->status->value,
                'events' => $recent->events_count,
            ])
            ->all();

        return Response::json($result);
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    protected function events(Collection $events): array
    {
        return $events->map(fn (Event $event): array => [
            'id' => $event->id,
            'title' => $event->title,
            'date' => $event->starts_on->toDateString(),
            'time' => $event->time,
            'venue' => $event->venue,
            'city' => $event->city,
            'category' => $event->category,
            'cost' => $event->cost,
            'reason' => $event->reason,
            'wildcard' => $event->is_wildcard,
            'activity_id' => $event->activity_id,
            'url' => $event->url,
            'marked' => $event->status?->getLabel(),
        ])->values()->all();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The scan\'s id, from recent_scans. Leave out for the latest.'),
        ];
    }
}
