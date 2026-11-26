<?php

use App\Ai\Agents\EventScout;
use App\Ai\Agents\PageReader;
use App\Enums\EventStatus;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Filament\Pages\Upcoming;
use App\Jobs\RunSearch;
use App\Models\Event;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
});

function scan(User $user, array $attributes = []): Search
{
    return Search::factory()->for($user)->create([
        'kind' => SearchKind::Discovery,
        'location' => $user->home_location,
        'starts_on' => today(),
        'ends_on' => today()->addDays(14),
        'status' => SearchStatus::Completed,
        ...$attributes,
    ]);
}

it('scans the home area of every user who has one, once at a time', function () {
    Queue::fake();

    User::factory()->create(['home_location' => null]);
    $busy = User::factory()->create(['home_location' => 'Riverside']);
    scan($busy, ['status' => SearchStatus::Running]);

    $this->artisan('events:discover')->assertSuccessful();

    $search = $this->user->searches()->sole();

    expect($search)
        ->kind->toBe(SearchKind::Discovery)
        ->location->toBe('Springfield')
        ->starts_on->toDateString()->toBe('2026-11-01')
        ->ends_on->toDateString()->toBe('2026-11-22');

    expect($busy->searches()->count())->toBe(1);

    Queue::assertPushed(RunSearch::class, 1);
});

it('checks every hour for users due a scan', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains($event->command, 'events:discover'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('scans each user once a week, once their landing day has begun where they live', function () {
    Queue::fake();

    // It is 09:00 UTC on Sunday, Nov 1: 03:00 on Sunday in Chicago, and still 23:00 on Saturday in Honolulu.
    $user = fn (string $timezone, int $landingDay, string $lastScan): User => tap(
        User::factory()->create(['home_location' => 'Riverton', 'timezone' => $timezone, 'landing_day' => $landingDay]),
        fn (User $user) => scan($user, ['created_at' => $lastScan]),
    );

    $landsToday = $user('America/Chicago', CarbonInterface::SUNDAY, '2026-10-25 06:00:00');
    $alreadyScanned = $user('UTC', CarbonInterface::SUNDAY, '2026-11-01 01:00:00');
    $missedThursday = $user('UTC', CarbonInterface::THURSDAY, '2026-10-22 01:00:00');
    $checkedSinceThursday = $user('UTC', CarbonInterface::THURSDAY, '2026-10-31 15:00:00');
    $notSundayYet = $user('Pacific/Honolulu', CarbonInterface::SUNDAY, '2026-10-25 11:00:00');
    $this->user->update(['home_location' => null]);

    $this->artisan('events:discover')->assertSuccessful();

    expect($landsToday->searches()->count())->toBe(2)
        ->and($missedThursday->searches()->count())->toBe(2)
        ->and($alreadyScanned->searches()->count())->toBe(1)
        ->and($checkedSinceThursday->searches()->count())->toBe(1)
        ->and($notSundayYet->searches()->count())->toBe(1);

    expect($landsToday->latestScan()->starts_on->toDateString())->toBe('2026-11-01');

    Queue::assertPushed(RunSearch::class, 2);
});

it('shows and scans from the date where the user lives', function () {
    $this->travelTo('2026-11-02 03:00:00');
    $this->user->update(['timezone' => 'America/Chicago']);

    expect(Search::discoveryFor($this->user)->starts_on->toDateString())->toBe('2026-11-01');

    $tonight = Event::factory()->for(scan($this->user))->create(['starts_on' => '2026-11-01']);
    $yesterday = Event::factory()->for(scan($this->user))->create(['starts_on' => '2026-10-31']);

    $this->actingAs($this->user);

    Livewire::test(Upcoming::class)
        ->assertCanSeeTableRecords([$tonight])
        ->assertCanNotSeeTableRecords([$yesterday]);
});

it('briefs a scan with the watched pages and the events it already found', function () {
    Source::factory()->for($this->user)->create(['name' => 'Athletics', 'url' => 'https://athletics.example.org/calendar']);
    Event::factory()->for(scan($this->user))->create(['title' => 'Home opener', 'starts_on' => '2026-11-03']);

    $brief = Search::discoveryFor($this->user)->brief();

    expect($brief)
        ->toContain('Find things to do in or near Springfield between 2026-11-01 and 2026-11-22.')
        ->toContain('include its events in the date range that they could go to')
        ->toContain('add a note to sources')
        ->toContain('- Athletics: https://athletics.example.org/calendar')
        ->toContain("Before you answer, use your 9 web searches to find events they don't list")
        ->toContain('- 2026-11-03 Home opener')
        ->not->toContain('runs of their own');
});

it('asks a run to search the web even when Sunshine read all its pages for it', function () {
    $source = Source::factory()->for($this->user)->create(['name' => 'Athletics', 'url' => 'https://athletics.example.org/calendar']);

    $brief = Search::discoveryFor($this->user)->brief(listed: [$source->id => [['title' => 'Home opener', 'date' => '2026-11-07']]]);

    expect($brief)
        ->toContain("Athletics: https://athletics.example.org/calendar\n- 2026-11-07 · Home opener")
        ->not->toContain('Fetch each one')
        ->toContain("they cover only part of what's on. Before you answer, use your 9 web searches to find events they don't list, even if the pages already give you plenty to choose from.");
});

it('briefs a scan with the times they can go out', function () {
    expect(Search::discoveryFor($this->user)->brief())->not->toContain('can only go out');

    $this->user->update([
        'free_all_day' => [CarbonInterface::SUNDAY, CarbonInterface::SATURDAY],
        'free_evenings' => [CarbonInterface::FRIDAY, CarbonInterface::MONDAY, CarbonInterface::SATURDAY],
    ]);

    expect(Search::discoveryFor($this->user)->brief())
        ->toContain('They can only go out at these times')
        ->toContain("- Any time on Saturday and Sunday\n- In the evening, from about 5 PM, on Monday and Friday");
});

it('searches each of their other places in a run of its own, after home', function () {
    $athletics = Source::factory()->for($this->user)->create(['url' => 'https://athletics.example.org/calendar']);
    $arena = Source::factory()->for($this->user)->create(['url' => 'https://arena.example.org/shows', 'place' => 'Bayport, WA']);
    $moved = Source::factory()->for($this->user)->create(['url' => 'https://cedargrove.example.org/events', 'place' => 'Cedar Grove, OR']);
    $this->user->update(['places' => [
        ['location' => 'Bayport, WA', 'note' => 'only for big concerts'],
        ['location' => 'Maple Falls, OR', 'note' => null],
        ['location' => null, 'note' => 'never filled in'],
    ]]);

    EventScout::fake([
        ['events' => [['title' => 'Home opener', 'date' => '2026-11-03', 'url' => 'https://example.org/a']], 'sources' => [
            ['url' => 'https://athletics.example.org/calendar', 'note' => 'Home opener in range.'],
        ]],
        ['events' => [
            ['title' => 'Arena concert', 'date' => '2026-11-04', 'url' => 'https://example.org/b'],
            ['title' => 'Home opener', 'date' => '2026-11-03', 'url' => 'https://example.org/a'],
        ], 'sources' => [['url' => 'https://arena.example.org/shows', 'note' => 'One concert in range.']]],
        ['events' => [['title' => 'Holiday lights', 'date' => '2026-11-05', 'url' => 'https://example.org/c']], 'sources' => []],
    ]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    expect($search->refresh()->status)->toBe(SearchStatus::Completed)
        ->and($search->usages()->orderBy('id')->pluck('location')->all())->toBe(['Springfield', 'Bayport, WA', 'Maple Falls, OR'])
        ->and($search->events()->orderBy('starts_on')->pluck('title')->all())->toBe(['Home opener', 'Arena concert', 'Holiday lights'])
        ->and($search->events()->with('usage')->orderBy('starts_on')->get()->pluck('usage.location')->all())->toBe(['Springfield', 'Bayport, WA', 'Maple Falls, OR']);

    EventScout::assertPrompted(fn ($prompt) => $prompt->contains('in or near Springfield')
        && $prompt->contains('This scan searches Bayport, WA and Maple Falls, OR in runs of their own, so stay near Springfield')
        && $prompt->contains('https://athletics.example.org/calendar')
        && $prompt->contains('https://cedargrove.example.org/events')
        && ! $prompt->contains('only for big concerts')
        && ! $prompt->contains('https://arena.example.org/shows'));
    EventScout::assertPrompted(fn ($prompt) => $prompt->contains('in or near Bayport, WA')
        && $prompt->contains('This scan searches Springfield and Maple Falls, OR in runs of their own, so stay near Bayport, WA')
        && $prompt->contains("When they'd go here: only for big concerts")
        && $prompt->contains('https://arena.example.org/shows')
        && $prompt->contains('- 2026-11-03 Home opener')
        && ! $prompt->contains('https://athletics.example.org/calendar'));
    EventScout::assertPrompted(fn ($prompt) => $prompt->contains('in or near Maple Falls, OR')
        && ! $prompt->contains("When they'd go here")
        && ! $prompt->contains('check these pages')
        && $prompt->contains('- 2026-11-04 Arena concert'));

    expect($athletics->refresh()->check_note)->toBe('Home opener in range.')
        ->and($arena->refresh()->check_note)->toBe('One concert in range.')
        ->and($moved->refresh()->checked_at)->not->toBeNull();
});

it('checks an ad hoc search\'s own pages, and leaves them out of scans', function () {
    $search = Search::factory()->for($this->user)->create(['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08']);
    $schedule = Source::factory()->for($this->user)->for($search)->create(['name' => 'Festival', 'url' => 'https://festival.example.org/schedule']);
    Source::factory()->for($this->user)->create(['url' => 'https://athletics.example.org/calendar']);

    EventScout::fake([['events' => [], 'sources' => [['url' => 'https://festival.example.org/schedule', 'note' => 'Two shows in range.']]]]);

    RunSearch::dispatch($search->id);

    expect($schedule->refresh()->check_note)->toBe('Two shows in range.');
    EventScout::assertPrompted(fn ($prompt) => $prompt->contains('- Festival: https://festival.example.org/schedule')
        && ! $prompt->contains('https://athletics.example.org/calendar'));
    expect(Search::discoveryFor($this->user)->brief())->not->toContain('festival.example.org');
});

it('only searches where an ad hoc search says, whatever other places they have', function () {
    $this->user->update(['places' => [['location' => 'Maple Falls, OR']]]);

    EventScout::fake([['events' => []]]);

    $search = Search::factory()->for($this->user)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-08']);
    RunSearch::dispatch($search->id);

    expect($search->usages()->count())->toBe(1);
    EventScout::assertNotPrompted(fn ($prompt) => $prompt->contains('Maple Falls'));
});

it('leaves watched pages, known events, other places and free times out of ad hoc searches', function () {
    Source::factory()->for($this->user)->create();
    Event::factory()->for(scan($this->user))->create(['starts_on' => '2026-11-03']);
    $this->user->update(['free_all_day' => [CarbonInterface::SATURDAY], 'places' => [['location' => 'Maple Falls, OR']]]);

    $brief = Search::factory()->for($this->user)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-08'])->brief();

    expect($brief)
        ->not->toContain('check these pages')
        ->not->toContain('already know')
        ->not->toContain('can only go out')
        ->not->toContain('Maple Falls');
});

it('only keeps events a scan finds at times they can go out', function () {
    $this->user->update(['free_all_day' => [CarbonInterface::SATURDAY], 'free_evenings' => [CarbonInterface::WEDNESDAY]]);

    $found = fn (string $title, string $date, ?string $start = null, ?string $end = null): array => [
        'title' => $title, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'url' => 'https://example.org/'.Str::slug($title),
    ];

    EventScout::fake([['events' => [
        $found('Saturday morning market', '2026-11-07', '8:00', '12:00'),
        $found('Wednesday concert', '2026-11-04', '19:00'),
        $found('Wednesday afternoon fair', '2026-11-04', '15:00', '19:30'),
        $found('Wednesday lecture, time unknown', '2026-11-04'),
        $found('Wednesday lecture, time unreadable', '2026-11-04', '7 PM'),
        $found('Wednesday storytime', '2026-11-04', '10:30', '11:15'),
        $found('Wednesday matinee', '2026-11-04', '14:00'),
        $found('Tuesday talk', '2026-11-03', '19:00'),
    ]]]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    expect($search->events()->orderBy('title')->pluck('title')->all())->toBe([
        'Saturday morning market',
        'Wednesday afternoon fair',
        'Wednesday concert',
        'Wednesday lecture, time unknown',
        'Wednesday lecture, time unreadable',
    ]);
});

it('only keeps events a scan has not found before', function () {
    Event::factory()->for(scan($this->user))->create(['title' => 'Home Opener', 'starts_on' => '2026-11-03']);

    EventScout::fake([['events' => [
        ['title' => 'home  opener', 'date' => '2026-11-03', 'url' => 'https://example.org/a'],
        ['title' => 'Home opener', 'date' => '2026-11-10', 'url' => 'https://example.org/b'],
        ['title' => 'Pottery class', 'date' => '2026-11-04', 'url' => 'https://example.org/c'],
        ['title' => 'Pottery Class', 'date' => '2026-11-04', 'url' => 'https://example.org/d'],
    ]]]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    expect($search->events()->orderBy('starts_on')->pluck('title')->all())->toBe(['Pottery class', 'Home opener']);
});

it('skips an event a scan found before under other words, in the same city on the same date', function () {
    $earlier = scan($this->user);

    foreach ([
        ['Nature Quest (outdoor scavenger hunt)', 'Maple Falls'],
        ['Maplefest 2026', 'Maple Falls'],
        ['"Unsettled Riverton": Unsettling Settled History', 'Riverton'],
        ['Classic Car Pumpkin Patch', 'Maple Falls'],
        ['Storytime', 'Maple Falls'],
    ] as [$title, $city]) {
        Event::factory()->for($earlier)->create(['title' => $title, 'city' => $city, 'starts_on' => '2026-11-07']);
    }

    $found = fn (string $title, string $city): array => [
        'title' => $title, 'city' => $city, 'date' => '2026-11-07', 'url' => 'https://example.org/'.Str::slug($title),
    ];

    EventScout::fake([['events' => [
        $found('Nature Quest', 'Maple Falls'),
        $found('2026 Maplefest', 'Maple Falls'),
        $found('“Unsettled Riverton”: Unsettling Settled History', 'Riverton'),
        $found('Holiday Lights: Joy to the World (opening night)', 'Maple Falls'),
        $found('Holiday Lights 2026: Joy to the World', 'Maple Falls'),
        $found('Pumpkin Patch and a Movie', 'Maple Falls'),
        $found('Toddler Storytime', 'Maple Falls'),
        $found('Nature Quest', 'Riverton'),
    ]]]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    expect($search->events()->orderBy('id')->get()->map(fn (Event $event): string => "{$event->title}, {$event->city}")->all())->toBe([
        'Holiday Lights: Joy to the World (opening night), Maple Falls',
        'Pumpkin Patch and a Movie, Maple Falls',
        'Toddler Storytime, Maple Falls',
        'Nature Quest, Riverton',
    ]);
});

it('reads watched pages for the scout, listing only their new events at times they can go, and leaves the rest for it to fetch', function () {
    $this->user->update(['free_all_day' => [CarbonInterface::SATURDAY]]);
    Event::factory()->for(scan($this->user))->create(['title' => 'Home opener', 'city' => 'Springfield', 'starts_on' => '2026-11-07']);
    $arena = Source::factory()->for($this->user)->create(['name' => 'Arena', 'url' => 'https://arena.example/shows']);
    $museum = Source::factory()->for($this->user)->create(['name' => 'Museum', 'url' => 'https://museum.example/calendar']);

    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'arena.example/shows' => Http::response('<h2>Shows</h2><p>'.str_repeat('Doors open an hour before each show. ', 10).'</p>', 200, ['Content-Type' => 'text/html']),
        'museum.example/calendar' => Http::response('Forbidden', 403),
    ]);

    $read = fn (string $title, string $date, ?string $start = null): array => [
        'title' => $title, 'date' => $date, 'start_time' => $start, 'end_time' => null, 'venue' => 'Arena', 'city' => 'Springfield', 'cost' => null, 'url' => null, 'description' => 'A show.',
    ];

    PageReader::fake([['events' => [
        [...$read('Saturday concert', '2026-11-07', '19:00'), 'end_time' => '21:00', 'cost' => '$10', 'url' => 'https://arena.example/concert'],
        $read('Home opener (season start)', '2026-11-07'),
        $read('Tuesday talk', '2026-11-03', '19:00'),
        $read('Last month\'s show', '2026-10-20'),
    ], 'note' => 'Lists shows through November.']]);

    EventScout::fake([['events' => [], 'sources' => [
        ['url' => 'https://arena.example/shows', 'note' => 'Took the concert.'],
        ['url' => 'https://museum.example/calendar', 'note' => 'Couldn\'t open it.'],
    ]]]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    EventScout::assertPrompted(fn ($prompt) => $prompt->contains("they have been read for you, so don't fetch them. Here are the events on each in the date range that are new to them and at times they can go out")
        && $prompt->contains("Arena: https://arena.example/shows\n- 2026-11-07 · 19:00–21:00 · Saturday concert · Arena, Springfield · $10 · https://arena.example/concert — A show.\n")
        && ! $prompt->contains('Home opener (season start)')
        && ! $prompt->contains('Tuesday talk')
        && ! $prompt->contains('Last month')
        && $prompt->contains("They asked you to check these pages too. Fetch each one and include its events in the date range that they could go to. For each page, add a note to sources saying what you found there and why you left anything out:\n- Museum: https://museum.example/calendar")
        && $prompt->contains('use your 8 web searches'));

    expect($arena->refresh())
        ->page_read->toBeTrue()
        ->events_read->toBe(4)
        ->read_note->toBe('Lists shows through November.')
        ->check_note->toBe('Took the concert.')
        ->and($museum->refresh())
        ->page_read->toBeFalse()
        ->events_read->toBeNull()
        ->read_note->toBe('It answered Sunshine with 403.')
        ->and($search->usages()->orderBy('id')->pluck('source_id')->all())->toBe([$arena->id, null]);
});

it('keeps a page read that prints something along the way, and logs what it printed', function () {
    Log::spy();
    Source::factory()->for($this->user)->create(['name' => 'Arena', 'url' => 'https://arena.example/shows']);

    Http::fake([
        'arena.example/robots.txt' => Http::response('', 404),
        'arena.example/shows' => Http::response('<p>'.str_repeat('Doors open an hour before each show. ', 10).'</p>', 200, ['Content-Type' => 'text/html']),
    ]);

    PageReader::fake(function (): array {
        echo 'Deprecated: something old';

        return ['events' => [['title' => 'Saturday concert', 'date' => '2026-11-07', 'start_time' => '19:00', 'description' => 'A show.']], 'note' => 'One show.'];
    });
    EventScout::fake([['events' => []]]);

    RunSearch::dispatch(Search::discoveryFor($this->user)->id);

    EventScout::assertPrompted(fn ($prompt) => $prompt->contains("Arena: https://arena.example/shows\n- 2026-11-07 · 19:00 · Saturday concert — A show."));
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'A page read printed output.' && $context['output'] === 'Deprecated: something old');
});

it('leaves every page to the scout\'s fetch when reading pages is turned off', function () {
    config(['sunshine.pages.read' => false]);
    Source::factory()->for($this->user)->create(['url' => 'https://arena.example/shows']);

    PageReader::fake();
    EventScout::fake([['events' => []]]);

    RunSearch::dispatch(Search::discoveryFor($this->user)->id);

    Http::assertNothingSent();
    PageReader::assertNeverPrompted();
    EventScout::assertPrompted(fn ($prompt) => $prompt->contains("They asked you to check these pages. Fetch each one and include its events in the date range that they could go to. For each page, add a note to sources saying what you found there and why you left anything out:\n- ")
        && ! $prompt->contains('read for you'));
});

it('notes what a scan made of each watched page, and leaves them alone on other searches', function () {
    $source = Source::factory()->for($this->user)->create(['url' => 'https://athletics.example.org/calendar']);

    EventScout::fake([
        ['events' => [], 'sources' => [['url' => 'https://athletics.example.org/calendar', 'note' => 'Nothing in range.']]],
        ['events' => [], 'sources' => []],
    ]);

    RunSearch::dispatch(Search::discoveryFor($this->user)->id);

    expect($source->refresh())
        ->checked_at->not->toBeNull()
        ->check_note->toBe('Nothing in range.');

    $this->travel(1)->hour();
    RunSearch::dispatch(Search::factory()->for($this->user)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-08'])->id);

    expect($source->refresh())
        ->checked_at->toDateTimeString()->toBe('2026-11-01 09:00:00')
        ->check_note->toBe('Nothing in range.');
});

it('shows upcoming events from scans, soonest first, without the ones already marked', function () {
    $this->actingAs($this->user);

    $scan = scan($this->user);
    $later = Event::factory()->for($scan)->create(['starts_on' => '2026-11-09']);
    $sooner = Event::factory()->for($scan)->create(['starts_on' => '2026-11-02']);
    $past = Event::factory()->for($scan)->create(['starts_on' => '2026-10-30']);
    $notForUs = Event::factory()->for($scan)->marked(EventStatus::NotForUs)->create(['starts_on' => '2026-11-03']);
    $interested = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-04']);
    $adHoc = Event::factory()->for(Search::factory()->for($this->user))->create(['starts_on' => '2026-11-02']);
    $someoneElses = Event::factory()->for(scan(User::factory()->create(['home_location' => 'Elsewhere'])))->create(['starts_on' => '2026-11-02']);

    Livewire::test(Upcoming::class)
        ->assertCanSeeTableRecords([$sooner, $later], inOrder: true)
        ->assertCanNotSeeTableRecords([$past, $notForUs, $interested, $adHoc, $someoneElses])
        ->removeTableFilter('hide_not_for_us')
        ->assertCanSeeTableRecords([$notForUs])
        ->assertCanNotSeeTableRecords([$interested])
        ->removeTableFilter('hide_interested')
        ->assertCanSeeTableRecords([$interested]);
});

it('says where it checks and when', function () {
    $this->actingAs($this->user);
    scan($this->user);

    Livewire::test(Upcoming::class)->assertSee('Sunshine checks Springfield every Thursday');

    $this->user->update(['places' => [['location' => 'Bayport, WA'], ['location' => 'Maple Falls, OR']]]);

    Livewire::test(Upcoming::class)->assertSee('Sunshine checks Springfield and 2 other places every Thursday');
});

it('checks for new events on demand', function () {
    Queue::fake();
    $this->actingAs($this->user);

    Livewire::test(Upcoming::class)
        ->callAction('scan')
        ->assertActionDisabled('scan');

    expect($this->user->isScanning())->toBeTrue();

    Queue::assertPushed(RunSearch::class);
});

it('cannot check before a home location is set', function () {
    $this->actingAs(User::factory()->create(['home_location' => null]));

    Livewire::test(Upcoming::class)
        ->assertActionDisabled('scan')
        ->assertSee('Where\'s home?');
});

it('keeps scans out of the searches list', function () {
    $this->actingAs($this->user);

    $this->get(route('filament.app.resources.searches.view', scan($this->user)))->assertNotFound();
});
