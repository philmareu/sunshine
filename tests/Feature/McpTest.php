<?php

use App\Ai\PageCheck;
use App\Enums\EventStatus;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Filament\Pages\Claude;
use App\Jobs\RunSearch;
use App\Mcp\Servers\SunshineServer;
use App\Mcp\Tools\AddSource;
use App\Mcp\Tools\CheckSource;
use App\Mcp\Tools\CreateSearch;
use App\Mcp\Tools\GetPreferences;
use App\Mcp\Tools\GetScan;
use App\Mcp\Tools\GetSearch;
use App\Mcp\Tools\ListSearches;
use App\Mcp\Tools\ListSources;
use App\Mcp\Tools\ListUpcoming;
use App\Mcp\Tools\RemoveSource;
use App\Models\AiUsage;
use App\Models\Event;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');
    $this->user = User::factory()->create(['home_location' => 'Riverton, OR']);
});

afterEach(function () {
    PageCheck::resolveHostsUsing(null);
});

/** An MCP initialize call, as Claude Code sends it. */
function initializeMcp(?string $token): TestResponse
{
    return test()->withHeaders(array_filter([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $token ? "Bearer {$token}" : null,
    ]))->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ]);
}

describe('the server', function () {
    it('turns away a request without a token', function () {
        initializeMcp(null)->assertUnauthorized();
    });

    it('serves a user\'s token', function () {
        initializeMcp($this->user->createToken(Claude::TOKEN_NAME)->plainTextToken)
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'sunshine');
    });

    it('makes the first token on the Connect Claude page straight away', function () {
        $this->actingAs($this->user);

        Livewire::test(Claude::class)
            ->mountAction('newToken')
            ->assertActionNotMounted('newToken')
            ->assertSee('claude mcp add --transport http --scope user sunshine');

        expect($this->user->tokens()->count())->toBe(1);
    });

    it('asks before replacing a token, and the new one ends the old', function () {
        $this->actingAs($this->user);
        $old = $this->user->createToken(Claude::TOKEN_NAME);

        Livewire::test(Claude::class)
            ->mountAction('newToken')
            ->assertActionMounted('newToken')
            ->assertDontSee('claude mcp add')
            ->callMountedAction()
            ->assertSee('claude mcp add --transport http --scope user sunshine');

        expect($this->user->tokens()->pluck('id')->all())->toHaveCount(1)->not->toContain($old->accessToken->id);
    });
});

describe('preferences and sources', function () {
    it('gives the family\'s preferences', function () {
        $this->user->update([
            'places' => [['location' => 'Bayport, WA', 'note' => null]],
            'free_all_day' => [CarbonInterface::SATURDAY],
            'interests' => ['crafts for kids'],
        ]);

        SunshineServer::actingAs($this->user)->tool(GetPreferences::class)
            ->assertOk()
            ->assertSee(['"home_location":"Riverton, OR"', '"location":"Bayport, WA"', '"today":"2026-11-01"', '"free_all_day":["Saturday"]', '"looking_for":["crafts for kids"]']);
    });

    it('lists only their own watched pages, with each one\'s place and last check', function () {
        $this->user->update(['places' => [['location' => 'Bayport, WA']]]);
        Source::factory()->for($this->user)->create(['name' => 'Arts Center', 'checked_at' => now(), 'page_read' => true, 'check_note' => 'Twelve shows in range.']);
        Source::factory()->for($this->user)->create([
            'name' => 'Watkins Museum', 'checked_at' => now(), 'page_read' => true, 'events_read' => 9, 'read_note' => 'Lists talks through December.', 'check_note' => 'Took two talks.',
        ]);
        Source::factory()->for($this->user)->create(['name' => 'Bayport Parks', 'place' => 'Bayport, WA']);
        Source::factory()->for($this->user)->for(Search::factory()->for($this->user))->create(['name' => 'Festival schedule']);
        Source::factory()->create(['name' => 'Someone else\'s']);

        SunshineServer::actingAs($this->user)->tool(ListSources::class)
            ->assertOk()
            ->assertSee([
                '"pages_per_run":10', '"name":"Arts Center","url"', '"place":null', '"page_read":true,"read_by":"scout","events_read":null', '"note":"Twelve shows in range."',
                '"read_by":"sunshine","events_read":9,"read_note":"Lists talks through December.","note":"Took two talks."', '"name":"Bayport Parks"', '"place":"Bayport, WA"',
            ])
            ->assertDontSee(['Festival schedule', 'Someone else']);
    });

    it('checks a page without saving it', function () {
        PageCheck::resolveHostsUsing(fn (): array => ['93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([
            'lied.example/robots.txt' => Http::response('', 404),
            'lied.example/events' => Http::response('<p>Nov 5, 2026</p><p>Nov 20, 2026</p>', 200, ['Content-Type' => 'text/html']),
        ]);

        SunshineServer::actingAs($this->user)->tool(CheckSource::class, ['url' => 'https://lied.example/events'])
            ->assertOk()
            ->assertSee('Looks readable: dates on 2 of the next 21 days, reaching 2026-11-20');

        expect(Source::count())->toBe(0);
    });

    it('checks a page as Sunshine\'s browser shows it, for one that fills in its events with JavaScript', function () {
        PageCheck::resolveHostsUsing(fn (): array => ['93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([
            'lakeside.example/robots.txt' => Http::response('', 404),
            'lakeside.example/events' => Http::response('<div id="app">Loading…</div>', 200, ['Content-Type' => 'text/html']),
        ]);
        Process::fake(['*render.mjs*' => Process::result(json_encode(['status' => 200, 'url' => 'https://lakeside.example/events', 'html' => '<p>Nov 5, 2026</p><p>Nov 20, 2026</p>']))]);

        SunshineServer::actingAs($this->user)->tool(CheckSource::class, ['url' => 'https://lakeside.example/events'])
            ->assertOk()
            ->assertSee('check it again in Sunshine\'s browser');
        SunshineServer::actingAs($this->user)->tool(CheckSource::class, ['url' => 'https://lakeside.example/events', 'browser' => true])
            ->assertOk()
            ->assertSee('Looks readable: dates on 2 of the next 21 days, reaching 2026-11-20');

        Process::assertRanTimes(fn ($process): bool => in_array('https://lakeside.example/events', $process->command, true), 1);
    });

    it('adds a page that needs a browser', function () {
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://lakeside.example/events', 'name' => 'Lakeside events', 'browser' => true])
            ->assertOk();

        expect($this->user->sources()->sole()->needs_browser)->toBeTrue();

        SunshineServer::actingAs($this->user)->tool(ListSources::class)
            ->assertSee('"name":"Lakeside events","url":"https://lakeside.example/events","place":null,"browser":true');
    });

    it('checks a page with one address for each day on today\'s', function () {
        PageCheck::resolveHostsUsing(fn (): array => ['93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([
            'cityguide.example/robots.txt' => Http::response('', 404),
            'cityguide.example/events/2026/11/01' => Http::response('<p>Sun, Nov 1</p><p>Jazz brunch, 11 am</p>', 200, ['Content-Type' => 'text/html']),
        ]);

        SunshineServer::actingAs($this->user)->tool(CheckSource::class, ['url' => 'https://cityguide.example/events/{date:Y/m/d}'])
            ->assertOk()
            ->assertSee(['https://cityguide.example/events/2026/11/01', 'Looks readable: its page for today is about'])
            ->assertDontSee('Its dates only reach');
    });

    it('adds a page with one address for each day, but not an address that is wrong once its date is filled in', function () {
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://cityguide.example/events/{date:Y/m/d}', 'name' => 'City Guide'])
            ->assertOk();
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'cityguide {date}'])
            ->assertHasErrors(['put {date} where the date goes']);

        expect($this->user->sources()->sole()->url)->toBe('https://cityguide.example/events/{date:Y/m/d}');
    });

    it('adds a source to a place\'s run and says how many pages that leaves it', function () {
        $this->user->update(['places' => [['location' => 'Bayport, WA']]]);
        Source::factory()->for($this->user)->count(3)->create();

        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://arts-center.example/events-all/', 'name' => 'Arts Center'])
            ->assertOk()
            ->assertSee('Now watching Arts Center in the run near home. That run opens 4 watched pages, which leaves it 6 web searches. Sunshine reads watched pages itself, so only the ones it can\'t read use the scout\'s 10 fetches.');
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://parks.example/events/', 'name' => 'Bayport Parks', 'place' => 'Bayport, WA'])
            ->assertOk()
            ->assertSee('Now watching Bayport Parks in the run for Bayport, WA. That run opens 1 watched page, which leaves it 9 web searches.');

        expect($this->user->sources()->latest('id')->first())
            ->url->toBe('https://parks.example/events/')
            ->place->toBe('Bayport, WA');
    });

    it('won\'t add a page for a place they don\'t list', function () {
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://cedargrove.example.org/events', 'place' => 'Cedar Grove, OR'])
            ->assertHasErrors(['That isn\'t one of their other places.']);

        expect(Source::count())->toBe(0);
    });

    it('won\'t add a page twice or one that isn\'t a web address', function () {
        Source::factory()->for($this->user)->create(['url' => 'https://arts-center.example/events-all/']);

        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'https://arts-center.example/events-all/'])
            ->assertHasErrors(['That page is already watched.']);
        SunshineServer::actingAs($this->user)->tool(AddSource::class, ['url' => 'javascript:alert(1)'])
            ->assertHasErrors();

        expect($this->user->sources()->count())->toBe(1);
    });

    it('removes only their own sources', function () {
        $mine = Source::factory()->for($this->user)->create(['name' => 'Explore Riverton']);
        $theirs = Source::factory()->create();

        SunshineServer::actingAs($this->user)->tool(RemoveSource::class, ['id' => $mine->id])
            ->assertOk()
            ->assertSee('Stopped watching Explore Riverton.');
        SunshineServer::actingAs($this->user)->tool(RemoveSource::class, ['id' => $theirs->id])
            ->assertHasErrors(['No watched page has that id. Call list-sources to see them.']);

        expect($mine->fresh())->toBeNull()
            ->and($theirs->fresh())->not->toBeNull();
    });
});

describe('searches', function () {
    it('starts a search near home unless told where, with pages for it to check', function () {
        Queue::fake([RunSearch::class]);

        SunshineServer::actingAs($this->user)->tool(CreateSearch::class, [
            'starts_on' => '2026-11-06',
            'ends_on' => '2026-11-08',
            'direction' => 'Indoors, it may rain',
            'sources' => [['url' => 'https://arts-center.example/events-all/', 'name' => 'Arts Center']],
        ])
            ->assertOk()
            ->assertSee('Searching Riverton, OR from 2026-11-06 to 2026-11-08');

        $search = $this->user->searches()->sole();

        expect($search)
            ->kind->toBe(SearchKind::AdHoc)
            ->direction->toBe('Indoors, it may rain')
            ->and($search->sources()->sole())
            ->name->toBe('Arts Center')
            ->user_id->toBe($this->user->id);

        Queue::assertPushed(RunSearch::class, fn (RunSearch $job): bool => $job->searchId === $search->id);
    });

    it('won\'t start a search with its dates the wrong way round or over three months', function () {
        Queue::fake([RunSearch::class]);

        SunshineServer::actingAs($this->user)->tool(CreateSearch::class, ['location' => 'Denver, CO', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-06'])
            ->assertHasErrors();
        SunshineServer::actingAs($this->user)->tool(CreateSearch::class, ['location' => 'Denver, CO', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-01'])
            ->assertHasErrors(['A search covers three months at most.']);

        expect(Search::count())->toBe(0);
        Queue::assertNotPushed(RunSearch::class);
    });

    it('lists their own ad hoc searches, not scans', function () {
        Search::factory()->for($this->user)->create(['location' => 'Denver, CO']);
        Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery, 'location' => 'Weekly scan']);
        Search::factory()->create(['location' => 'Someone else\'s trip']);

        SunshineServer::actingAs($this->user)->tool(ListSearches::class)
            ->assertOk()
            ->assertSee('"location":"Denver, CO"')
            ->assertDontSee(['Weekly scan', 'Someone else']);
    });

    it('gives a search\'s events, and only for their own searches', function () {
        $search = Search::factory()->for($this->user)->create(['location' => 'Denver, CO', 'status' => SearchStatus::Completed]);
        Event::factory()->for($search)->marked(EventStatus::Interested)->create(['title' => 'Botanic garden lights', 'starts_on' => '2026-11-07', 'reason' => 'They love outdoor light shows.']);
        $theirs = Search::factory()->create();

        Source::factory()->for($this->user)->for($search)->checked('Two shows in range.')->create(['name' => 'Festival']);

        SunshineServer::actingAs($this->user)->tool(GetSearch::class, ['id' => $search->id])
            ->assertOk()
            ->assertSee(['"name":"Festival"', '"note":"Two shows in range."', '"status":"completed"', '"title":"Botanic garden lights"', '"date":"2026-11-07"', '"reason":"They love outdoor light shows."', '"marked":"Interested"']);
        SunshineServer::actingAs($this->user)->tool(GetSearch::class, ['id' => $theirs->id])
            ->assertHasErrors(['No search has that id. Call list-searches to see them.']);
    });
});

/** One of a scan's runs, as RunSearch records it. */
function scanRun(Search $scan, string $place, int $webSearches, float $cost): AiUsage
{
    return AiUsage::query()->create([
        'user_id' => $scan->user_id, 'search_id' => $scan->id, 'location' => $place, 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5',
        'input_tokens' => 50_000, 'cache_write_tokens' => 20_000, 'cache_read_tokens' => 100_000, 'output_tokens' => 4_000,
        'web_searches' => $webSearches, 'pages_fetched' => 6, 'cost' => $cost,
    ]);
}

describe('scans', function () {
    it('gives the latest scan run by run, with the new events each run kept', function () {
        $older = Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery, 'location' => 'Riverton, OR']);
        Event::factory()->for($older)->create(['title' => 'Found last week']);

        $scan = Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery, 'location' => 'Riverton, OR', 'status' => SearchStatus::Completed]);
        $home = scanRun($scan, 'Riverton, OR', webSearches: 4, cost: 0.25);
        AiUsage::query()->create([
            'user_id' => $scan->user_id, 'search_id' => $scan->id, 'source_id' => Source::factory()->for($this->user)->create(['name' => 'Maple Falls Zoo'])->id,
            'location' => 'Maple Falls, OR', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input_tokens' => 9_000, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'output_tokens' => 1_200, 'web_searches' => 0, 'pages_fetched' => 0, 'cost' => 0.0015,
        ]);
        $calendar = Source::factory()->for($this->user)->create(['name' => 'Maple Falls calendar', 'url' => 'https://maplefalls.example/events/{date}']);

        foreach (['2026-11-04', '2026-11-07'] as $day) {
            AiUsage::query()->create([
                'user_id' => $scan->user_id, 'search_id' => $scan->id, 'source_id' => $calendar->id,
                'location' => 'Maple Falls, OR', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input_tokens' => 5_000, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'output_tokens' => 2_000, 'web_searches' => 0, 'pages_fetched' => 0, 'cost' => 0.0015,
            ]);
        }

        $mapleFalls = scanRun($scan, 'Maple Falls, OR', webSearches: 6, cost: 0.2);
        $brickClub = Event::factory()->for($scan)->for($home, 'usage')->create(['title' => 'Brick Builders Club', 'starts_on' => '2026-11-08']);
        $holidayLights = Event::factory()->for($scan)->for($mapleFalls, 'usage')->create(['title' => 'Holiday Lights', 'starts_on' => '2026-11-20', 'reason' => 'They loved the zoo.']);

        SunshineServer::actingAs($this->user)->tool(GetScan::class)
            ->assertOk()
            ->assertSee([
                '"id":'.$scan->id.',"location":"Riverton, OR"', '"status":"completed"', '"cost":0.4545',
                '"place":"Riverton, OR","model":"claude-sonnet-5-5","pages_read":[]',
                '"place":"Maple Falls, OR","model":"claude-sonnet-5-5","pages_read":[{"page":"Maple Falls Zoo","model":"claude-haiku-5-5","reads":1,"input_tokens":9000,"output_tokens":1200,"cost":0.0015},{"page":"Maple Falls calendar","model":"claude-haiku-5-5","reads":2,"input_tokens":10000,"output_tokens":4000,"cost":0.003}]',
                '"web_searches":6', '"reason":"They loved the zoo."',
                '"cost":0.25,"events":[{"id":'.$brickClub->id.',', '"cost":0.2,"events":[{"id":'.$holidayLights->id.',',
                '"recent_scans":[{"id":'.$scan->id.',', '{"id":'.$older->id.',',
            ])
            ->assertDontSee(['Found last week', 'events_without_run', '"place":"Maple Falls, OR","model":"claude-haiku-5-5"']);
    });

    it('gives an earlier scan by id, with events from before runs were recorded on them', function () {
        $older = Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery, 'location' => 'Riverton, OR']);
        Event::factory()->for($older)->create(['title' => 'Found last week']);
        Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery, 'location' => 'Riverton, OR']);

        SunshineServer::actingAs($this->user)->tool(GetScan::class, ['id' => $older->id])
            ->assertOk()
            ->assertSee(['"runs":[]', '"events_without_run":[{', '"title":"Found last week"']);
    });

    it('only gives their own scans', function () {
        SunshineServer::actingAs($this->user)->tool(GetScan::class)
            ->assertHasErrors(['There hasn\'t been a scan yet.']);

        $theirs = Search::factory()->create(['kind' => SearchKind::Discovery]);
        $trip = Search::factory()->for($this->user)->create(['location' => 'Denver, CO']);

        foreach ([$theirs, $trip] as $search) {
            SunshineServer::actingAs($this->user)->tool(GetScan::class, ['id' => $search->id])
                ->assertHasErrors(['No scan has that id. Leave it out to get the latest.']);
        }
    });

    it('lists what Upcoming shows across scans, with counts by city, and one city\'s on request', function () {
        $lastWeek = Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery]);
        $thisWeek = Search::factory()->for($this->user)->create(['kind' => SearchKind::Discovery]);
        Event::factory()->for($lastWeek)->create(['title' => 'Holiday Lights', 'city' => 'Maple Falls', 'starts_on' => '2026-11-20']);
        Event::factory()->for($thisWeek)->create(['title' => 'Maple Falls Holiday Lights', 'city' => 'Maple Falls', 'starts_on' => '2026-11-20']);
        Event::factory()->for($thisWeek)->create(['title' => 'Brick Builders Club', 'city' => 'Riverton', 'starts_on' => '2026-11-08', 'hidden_at' => now()]);
        Event::factory()->for($lastWeek)->create(['title' => 'Boo at the Zoo', 'city' => 'Maple Falls', 'starts_on' => '2026-10-31']);
        Event::factory()->for(Search::factory()->for($this->user)->create())->create(['title' => 'Denver lights', 'city' => 'Denver']);
        Event::factory()->for(Search::factory()->create(['kind' => SearchKind::Discovery]))->create(['title' => 'Someone else\'s find']);

        SunshineServer::actingAs($this->user)->tool(ListUpcoming::class)
            ->assertOk()
            ->assertSee(['"today":"2026-11-01"', '"by_city":{"Maple Falls":2,"Riverton":1}', '"title":"Brick Builders Club"', '"marked":null,"cant_make_it":true', '"title":"Maple Falls Holiday Lights"', '"title":"Holiday Lights"', '"scan":'.$lastWeek->id.','])
            ->assertDontSee(['Boo at the Zoo', 'Denver lights', 'Someone else']);

        SunshineServer::actingAs($this->user)->tool(ListUpcoming::class, ['city' => 'maple falls'])
            ->assertSee(['"by_city":{"Maple Falls":2,"Riverton":1}', '"title":"Holiday Lights"'])
            ->assertDontSee('"title":"Brick Builders Club"');
    });
});
