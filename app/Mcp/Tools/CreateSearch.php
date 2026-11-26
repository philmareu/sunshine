<?php

namespace App\Mcp\Tools;

use App\Ai\Agents\EventScout;
use App\Jobs\RunSearch;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Start an ad hoc search for things to do in a place between two dates, for a trip or something specific. The scout searches the web with the family\'s preferences and what they marked before, and opens any pages given for this search first (run check-source on them). It takes a minute or two and costs about as much as one place in a scan. Then call get-search with its id for the events and what it found on each page. It also appears under Searches in Sunshine.')]
#[IsOpenWorld]
class CreateSearch extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'location' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'direction' => ['nullable', 'string', 'max:1000'],
            'sources' => ['nullable', 'array', 'max:'.EventScout::MAX_PAGES_FETCHED],
            'sources.*.url' => ['required', 'string', 'max:2048', Source::addressRule()],
            'sources.*.name' => ['nullable', 'string', 'max:255'],
            'sources.*.browser' => ['nullable', 'boolean'],
        ]);

        $location = $validated['location'] ?? $user->home_location;

        if (blank($location)) {
            return Response::error('Say where to search: they have no home location set.');
        }

        if (CarbonImmutable::parse($validated['ends_on'])->gt(CarbonImmutable::parse($validated['starts_on'])->addMonths(3))) {
            return Response::error('A search covers three months at most.');
        }

        $search = $user->searches()->create([...Arr::except($validated, 'sources'), 'location' => $location]);

        foreach ($validated['sources'] ?? [] as $source) {
            $search->sources()->create([...Arr::except($source, 'browser'), 'needs_browser' => (bool) ($source['browser'] ?? false), 'user_id' => $user->id]);
        }

        RunSearch::dispatch($search->id);

        return Response::text(sprintf(
            'Searching %s from %s to %s as search %d. Results take a minute or two: call get-search with that id. In Sunshine: %s',
            $search->location,
            $search->starts_on->toDateString(),
            $search->ends_on->toDateString(),
            $search->id,
            route('filament.app.resources.searches.view', $search),
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()->description('A town, city or neighbourhood, e.g. "Denver, CO". Leave out to search near home.'),
            'starts_on' => $schema->string()->description('The first day, as YYYY-MM-DD.')->required(),
            'ends_on' => $schema->string()->description('The last day, as YYYY-MM-DD; at most three months after the first.')->required(),
            'direction' => $schema->string()->description('Anything specific to look for, e.g. "rainy day, something indoors for the kids".'),
            'sources' => $schema->array()->description('Pages to check for this search only, such as a festival\'s schedule; at most 10.')->items($schema->object([
                'url' => $schema->string()->description('The page\'s full address, with {date} or {date:Y/m/d} where the date goes for a page with one address for each day, which the search reads for each of its days (at most '.Source::MAX_DAYS.').')->required(),
                'name' => $schema->string()->description('A short name for it.'),
                'browser' => $schema->boolean()->description('Show it in Sunshine\'s browser first, for a page that fills in its events with JavaScript.'),
            ])),
        ];
    }
}
