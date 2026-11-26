<?php

namespace App\Mcp\Tools;

use App\Ai\Agents\EventScout;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Watch a page: every scan opens it, in the run near home or in the run for one of their other places, and notes what it found there. Run check-source on it first. Sunshine reads watched pages itself before each run, and only the ones it can\'t read use the scout\'s 10 fetches. Each watched page takes one of that run\'s web searches, down to '.EventScout::MIN_WEB_SEARCHES.', so add a few of the best pages to each run rather than many.')]
class AddSource extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $places = array_column($user->otherPlaces(), 'location');

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', Source::addressRule()],
            'name' => ['nullable', 'string', 'max:255'],
            'place' => ['nullable', 'string', Rule::in($places)],
            'browser' => ['nullable', 'boolean'],
        ], [
            'place.in' => 'That isn\'t one of their other places. Call get-preferences to see them, or leave place out for the run near home.',
        ]);

        if ($user->watchedSources()->where('url', $validated['url'])->exists()) {
            return Response::error('That page is already watched.');
        }

        $source = $user->sources()->create([...Arr::except($validated, 'browser'), 'needs_browser' => (bool) ($validated['browser'] ?? false)]);
        $inRun = $user->watchedSources()->get()->filter(fn (Source $watched): bool => $watched->runsIn($places) === $source->place)->count();

        $webSearches = EventScout::webSearchesFor($inRun);

        return Response::text(sprintf(
            'Now watching %s in the run %s. That run opens %d watched %s, which leaves it %d web %s. Sunshine reads watched pages itself, so only the ones it can\'t read use the scout\'s %d fetches.',
            $source->name ?? $source->url,
            $source->place ? "for {$source->place}" : 'near home',
            $inRun,
            Str::plural('page', $inRun),
            $webSearches,
            Str::plural('search', $webSearches),
            EventScout::MAX_PAGES_FETCHED,
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The page\'s full address. For a page with one address for each day, such as https://cityguide.example.com/events/2026/11/07, put {date} where the date goes, or {date:Y/m/d} to give its form in PHP\'s date format letters: https://cityguide.example.com/events/{date:Y/m/d}. Each scan then reads it once for each day they\'re free.')->required(),
            'name' => $schema->string()->description('A short name for it, e.g. "Arts Center".'),
            'place' => $schema->string()->description('One of their other places, exactly as get-preferences gives it, e.g. "Bayport, WA", for a page about that place. Leave out for a page near home.'),
            'browser' => $schema->boolean()->description('Show it in Sunshine\'s browser before reading it, for a page that fills in its events with JavaScript: one check-source finds dates on only with browser on.'),
        ];
    }
}
