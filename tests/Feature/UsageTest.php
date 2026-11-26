<?php

use App\Ai\Agents\EventScout;
use App\Ai\Pricing;
use App\Enums\SearchKind;
use App\Filament\Resources\AiUsages\Pages\ListAiUsages;
use App\Jobs\RunSearch;
use App\Models\AiUsage;
use App\Models\Search;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;

function step(TextUsage $usage, int $webSearches = 0, int $pagesFetched = 0): Step
{
    $calls = [];

    foreach (range(1, $pagesFetched) as $i) {
        if ($pagesFetched === 0) {
            break;
        }

        $calls[] = new ProviderToolCall("srvtoolu_page_{$i}", 'server_tool_use', ['type' => 'server_tool_use', 'name' => 'web_fetch']);
        $calls[] = new ProviderToolCall("srvtoolu_page_{$i}", 'web_fetch_tool_result', ['type' => 'web_fetch_tool_result', 'content' => ['type' => 'web_fetch_result', 'url' => "https://example.org/{$i}"]]);
    }

    foreach (range(1, $webSearches) as $i) {
        if ($webSearches === 0) {
            break;
        }

        $calls[] = new ProviderToolCall("srvtoolu_{$i}", 'server_tool_use', ['type' => 'server_tool_use', 'name' => 'web_search']);
        $calls[] = new ProviderToolCall("srvtoolu_{$i}", 'web_search_tool_result', ['type' => 'web_search_tool_result']);
    }

    // A fetch that failed: neither a web search nor a page.
    $calls[] = new ProviderToolCall('srvtoolu_fetch', 'server_tool_use', ['type' => 'server_tool_use', 'name' => 'web_fetch']);
    $calls[] = new ProviderToolCall('srvtoolu_fetch', 'web_fetch_tool_result', ['type' => 'web_fetch_tool_result', 'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible']]);

    return new Step('', [], [], FinishReason::Stop, $usage, new Meta('anthropic', 'claude-sonnet-5-5'), '', [], $calls);
}

it('prices tokens at the model\'s rates', function () {
    // 7,000 uncached input, 1,000 cache writes, 2,000 cache reads and 1,000 output on Sonnet 5.5, plus two searches.
    $usage = new TextUsage(inputTokens: 10_000, outputTokens: 1_000, cacheReadInputTokens: 2_000, cacheWriteInputTokens: 1_000);

    expect(Pricing::cost('claude-sonnet-5-5', $usage, webSearches: 2))->toEqualWithDelta(0.0467, 0.000001);
});

it('prices long Haiku prompts at the long context rates', function () {
    expect(Pricing::cost('claude-haiku-5-5', new TextUsage(50_000, 1_000)))->toEqualWithDelta(0.0055, 0.000001)
        ->and(Pricing::cost('claude-haiku-5-5', new TextUsage(150_000, 1_000)))->toEqualWithDelta(0.0775, 0.000001);
});

it('has no price for a model it does not know', function () {
    expect(Pricing::cost('some-other-model', new TextUsage(1_000, 1_000)))->toBeNull();
});

it('records each step of a run and counts its web searches and the pages it fetched', function () {
    $search = Search::factory()->create();

    $response = (new StructuredAgentResponse('inv', ['events' => []], '{}', new TextUsage(30_000, 1_500, 2_000, 1_000), new Meta('anthropic', 'claude-sonnet-5-5')))
        ->withSteps(collect([
            step(new TextUsage(10_000, 1_000, 2_000, 1_000), webSearches: 2, pagesFetched: 1),
            step(new TextUsage(20_000, 500), pagesFetched: 2),
        ]));

    $usage = AiUsage::record($search, $response);

    expect($usage)
        ->user_id->toBe($search->user_id)
        ->search_id->toBe($search->id)
        ->model->toBe('claude-sonnet-5-5')
        ->input_tokens->toBe(30_000)
        ->cache_read_tokens->toBe(2_000)
        ->cache_write_tokens->toBe(1_000)
        ->output_tokens->toBe(1_500)
        ->web_searches->toBe(2)
        ->pages_fetched->toBe(3)
        ->cost->toEqualWithDelta(0.0917, 0.000001);
});

function fakeAnthropic(): void
{
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => '{"events":[],"sources":[]}']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1_000, 'output_tokens' => 100],
    ])]);
}

it('asks Anthropic to cache the run, work at medium effort, filter what it finds and cut off long pages', function () {
    fakeAnthropic();

    (new EventScout(User::factory()->create()))->prompt('Find things to do in or near Riverside between 2026-11-01 and 2026-11-08.');

    Http::assertSent(function (Request $request): bool {
        $tools = collect($request['tools'])->keyBy('name');

        return $request['cache_control'] === ['type' => 'ephemeral']
            && $request['output_config']['effort'] === 'medium'
            && isset($request['output_config']['format'])
            && $tools['web_search']['type'] === 'web_search_20260318'
            && $tools['web_fetch']['type'] === 'web_fetch_20260318'
            && $tools['web_fetch']['max_content_tokens'] === EventScout::MAX_PAGE_TOKENS;
    });
});

it('searches near the run\'s place, and less the more pages it has to check', function (string $location, int $pages, array $userLocation, int $searches) {
    fakeAnthropic();

    (new EventScout(User::factory()->create(), location: $location, pagesToCheck: $pages))->prompt('Find things to do.');

    Http::assertSent(fn (Request $request): bool => collect($request['tools'])->firstWhere('name', 'web_search') == [
        'type' => 'web_search_20260318',
        'name' => 'web_search',
        'max_uses' => $searches,
        'user_location' => ['type' => 'approximate', ...$userLocation],
    ]);
})->with([
    'no pages' => ['Riverton, OR', 0, ['city' => 'Riverton', 'region' => 'OR'], 10],
    'a few pages' => ['Riverton, OR', 3, ['city' => 'Riverton', 'region' => 'OR'], 7],
    'many pages' => ['Bayport Metro', 8, ['city' => 'Bayport Metro'], 4],
]);

it('records usage when a search runs', function () {
    $search = Search::factory()->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-08']);

    EventScout::fake([
        new StructuredTextResponse(['events' => []], '{"events":[]}', new TextUsage(4_000, 800), new Meta('anthropic', 'claude-sonnet-5-5')),
    ]);

    RunSearch::dispatch($search->id);

    expect($search->usages()->sole())
        ->location->toBe($search->location)
        ->input_tokens->toBe(4_000)
        ->output_tokens->toBe(800)
        ->cost->toEqualWithDelta(0.016, 0.000001);
});

it('keeps usage when its search is deleted', function () {
    $search = Search::factory()->create();
    $usage = AiUsage::record($search, (new StructuredAgentResponse('inv', [], '{}', new TextUsage(1_000, 100), new Meta('anthropic', 'claude-sonnet-5-5')))->withSteps(collect([step(new TextUsage(1_000, 100))])));

    $search->delete();

    expect($usage->refresh()->search_id)->toBeNull();
});

it('lists only your own usage', function () {
    $user = User::factory()->create();
    $meta = new Meta('anthropic', 'claude-sonnet-5-5');
    $response = fn () => (new StructuredAgentResponse('inv', [], '{}', new TextUsage(1_000, 100), $meta))->withSteps(collect([step(new TextUsage(1_000, 100))]));

    $mine = AiUsage::record(Search::factory()->for($user)->create(), $response());
    $theirs = AiUsage::record(Search::factory()->create(), $response());

    $this->actingAs($user);

    Livewire::test(ListAiUsages::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('shows where each run searched', function () {
    $user = User::factory()->create();
    $scan = Search::factory()->for($user)->create(['kind' => SearchKind::Discovery, 'location' => 'Riverton, OR']);
    $response = fn () => (new StructuredAgentResponse('inv', [], '{}', new TextUsage(1_000, 100), new Meta('anthropic', 'claude-sonnet-5-5')))->withSteps(collect([step(new TextUsage(1_000, 100))]));

    $home = AiUsage::record($scan, $response());
    $bayport = AiUsage::record($scan, $response(), 'Bayport, WA');
    $older = AiUsage::record($scan, $response());
    $older->update(['location' => null]);

    $this->actingAs($user);

    Livewire::test(ListAiUsages::class)
        ->assertTableColumnStateSet('location', 'Riverton, OR', $home)
        ->assertTableColumnStateSet('location', 'Bayport, WA', $bayport)
        ->assertTableColumnStateSet('location', 'Riverton, OR', $older);
});
