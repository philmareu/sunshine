<?php

namespace App\Mcp\Tools;

use App\Ai\PageCheck;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Check whether a scan could get events out of a page, from Sunshine\'s server, which is where a scan reads it too: whether it answers, whether its content type is one Sunshine reads (HTML, RSS and Atom feeds, iCal, JSON or text; PDFs are left to the scout\'s fetch), whether robots.txt turns away Anthropic\'s fetcher (Sunshine follows the same rules), roughly how many tokens its text is (a scan reads about the first 50,000), and how many days coming up its dates fall on and how far they reach. No upcoming dates usually means a calendar filled in by JavaScript: check it again with browser on, and if that finds them, add it with browser on too. Saves nothing. A page that turns Sunshine away is left to the scout\'s fetch, from Anthropic\'s servers, which some sites let in: a scan\'s note on the source is the final word.')]
#[IsReadOnly]
#[IsOpenWorld]
class CheckSource extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', Source::addressRule()],
            'browser' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $today = $user->today();
        $daysAhead = config('sunshine.discovery.days_ahead');
        $browser = (bool) ($validated['browser'] ?? false);

        if (! Source::hasDate($validated['url'])) {
            return Response::json(PageCheck::run($validated['url'], $today, $daysAhead, browser: $browser));
        }

        // A page with one address for each day is checked on today's.
        $result = PageCheck::run(Source::fillDate($validated['url'], $today), $today, $daysAhead, oneDay: true, browser: $browser);

        if ($result['problems'] === []) {
            $result['summary'] = sprintf(
                'Looks readable: its page for today is about %s tokens. A scan reads its page for each day they\'re free, up to %d days.',
                number_format($result['approx_tokens']),
                Source::MAX_DAYS,
            );
        }

        return Response::json(['checked_url' => $result['url'], ...$result, 'url' => $validated['url']]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The page\'s full address, e.g. https://arts-center.example.com/events-all/. For a page with one address for each day, put {date} where the date goes, or {date:Y/m/d} to give its form, e.g. https://cityguide.example.com/events/{date:Y/m/d}; its page for today is checked.')->required(),
            'browser' => $schema->boolean()->description('Check it as Sunshine\'s browser shows it, for a page that fills in its events with JavaScript: one that has no dates without it. Takes a few seconds longer.'),
        ];
    }
}
