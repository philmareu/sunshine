<?php

use App\Filament\Resources\Sources\Pages\ManageSources;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Filament\Actions\CreateAction;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Livewire\Livewire;

it('adds a page to watch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->callAction(CreateAction::class, ['url' => 'https://athletics.example.org/calendar', 'name' => 'Athletics'])
        ->assertHasNoActionErrors();

    expect(Source::query()->sole())
        ->user_id->toBe($user->id)
        ->url->toBe('https://athletics.example.org/calendar');
});

it('checks a page in the search for one of their other places', function () {
    $user = User::factory()->create(['places' => [['location' => 'Bayport, WA']]]);
    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->callAction(CreateAction::class, ['url' => 'https://parks.example/events/', 'place' => 'Bayport, WA'])
        ->assertHasNoActionErrors();

    expect(Source::query()->sole()->place)->toBe('Bayport, WA');
});

it('shows each page\'s place, near home when its place is gone', function () {
    $user = User::factory()->create(['places' => [['location' => 'Bayport, WA']]]);
    $bayport = Source::factory()->for($user)->create(['place' => 'Bayport, WA']);
    $home = Source::factory()->for($user)->create();
    $gone = Source::factory()->for($user)->create(['place' => 'Cedar Grove, OR']);

    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->assertTableColumnStateSet('place', 'Bayport, WA', $bayport)
        ->assertTableColumnStateSet('place', 'Near home', $home)
        ->assertTableColumnStateSet('place', 'Near home', $gone);
});

it('adds a page with one address for each day', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageSources::class)
        ->callAction(CreateAction::class, ['url' => 'https://cityguide.example/events/{date:Y/m/d}', 'name' => 'City Guide'])
        ->assertHasNoActionErrors();

    expect(Source::query()->sole()->url)->toBe('https://cityguide.example/events/{date:Y/m/d}');
});

it('marks a page as needing a browser', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageSources::class)
        ->callAction(CreateAction::class, ['url' => 'https://lakeside.example/events', 'needs_browser' => true])
        ->assertHasNoActionErrors();

    expect(Source::query()->sole()->needs_browser)->toBeTrue();
});

it('rejects something that is not a web address', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageSources::class)
        ->callAction(CreateAction::class, ['url' => 'not a url'])
        ->assertHasActionErrors(['url']);
});

it('only lists your own watched pages, not pages for one search', function () {
    $user = User::factory()->create();
    $mine = Source::factory()->for($user)->create();
    $forSearch = Source::factory()->for($user)->for(Search::factory()->for($user))->create();
    $theirs = Source::factory()->create();

    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$forSearch, $theirs]);
});

it('records whether a scan read each page, going by the fetches the provider reports', function () {
    $this->travelTo('2026-11-01 09:00:00');

    $rowing = Source::factory()->create(['url' => 'https://athletics.example.org/rowing/schedule']);
    $library = Source::factory()->create(['url' => 'https://library.example.org/events']);
    $calendar = Source::factory()->create(['url' => 'https://city.example/events/{date:Y/m/d}']);
    $meta = new Meta('anthropic', 'claude-sonnet-5-5');

    $response = (new StructuredAgentResponse('inv', ['events' => [], 'sources' => [
        ['url' => 'https://athletics.example.org/rowing/schedule', 'note' => 'One race in range, away at Princeton, so left out.'],
        ['url' => 'https://library.example.org/events', 'note' => 'Storytime on Tuesday, included.'],
    ]], '{}', new TextUsage(1_000, 100), $meta))->withSteps(collect([
        new Step('', [], [], FinishReason::Stop, new TextUsage(1_000, 100), $meta, '', [], [
            new ProviderToolCall('srvtoolu_1', 'server_tool_use', ['name' => 'web_fetch', 'input' => ['url' => 'https://www.athletics.example.org/rowing/schedule/']]),
            new ProviderToolCall('srvtoolu_1', 'web_fetch_tool_result', ['content' => ['type' => 'web_fetch_result', 'url' => 'https://www.athletics.example.org/rowing/schedule/']]),
            new ProviderToolCall('srvtoolu_2', 'server_tool_use', ['name' => 'web_fetch', 'input' => ['url' => 'https://library.example.org/events']]),
            new ProviderToolCall('srvtoolu_2', 'web_fetch_tool_result', ['content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible']]),
            new ProviderToolCall('srvtoolu_3', 'server_tool_use', ['name' => 'web_fetch', 'input' => ['url' => 'https://city.example/events/2026/11/07']]),
            new ProviderToolCall('srvtoolu_3', 'web_fetch_tool_result', ['content' => ['type' => 'web_fetch_result', 'url' => 'https://city.example/events/2026/11/07']]),
        ]),
    ]));

    $rowing->recordCheck($response);
    $library->recordCheck($response);
    $calendar->recordCheck($response);

    expect($rowing->refresh())
        ->checked_at->toDateTimeString()->toBe('2026-11-01 09:00:00')
        ->page_read->toBeTrue()
        ->check_note->toBe('One race in range, away at Princeton, so left out.');

    // The scout claimed to have read it, but the fetch failed.
    expect($library->refresh())
        ->page_read->toBeFalse()
        ->check_note->toBe('Storytime on Tuesday, included.');

    // Fetching its page for one day reads a page with one address for each day.
    expect($calendar->refresh()->page_read)->toBeTrue();
});

it('lists each page by its name, leaving its address to the edit form', function () {
    $user = User::factory()->create();
    $named = Source::factory()->for($user)->create(['name' => 'College museums', 'url' => 'https://calendar.college.example/widget/view?schools=ku&days=21&num=50&format=html']);
    $browser = Source::factory()->for($user)->create(['name' => 'Grand Theater', 'url' => 'https://www.grand-theater.example/upcoming-events', 'needs_browser' => true]);
    $untitled = Source::factory()->for($user)->create(['name' => null, 'url' => 'https://www.riverton.example/events/']);

    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->assertTableColumnStateSet('name', 'College museums', $named)
        ->assertTableColumnDoesNotHaveDescription('name', 'Opened in a browser', $named)
        ->assertTableColumnHasDescription('name', 'Opened in a browser', $browser)
        ->assertTableColumnStateSet('name', 'riverton.example/events/', $untitled)
        ->assertDontSee('calendar.college.example');
});

it('shows what the last scan made of each page', function () {
    $user = User::factory()->create();
    Source::factory()->for($user)->checked('One race in range, away at Princeton, so left out.')->create();
    Source::factory()->for($user)->checked('Three shows that suit them.')->create(['events_read' => 12, 'read_note' => 'Lists shows through December.']);
    Source::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ManageSources::class)
        ->assertSee('One race in range, away at Princeton, so left out.')
        ->assertSee('read by the scout')
        ->assertSee('read by Sunshine, 12 events')
        ->assertSee('Not checked yet');
});
