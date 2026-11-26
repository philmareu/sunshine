<?php

use App\Ai\Agents\EventScout;
use App\Ai\Agents\PageReader;
use App\Enums\SearchKind;
use App\Jobs\RunSearch;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');

    // Free on Saturdays and on Wednesday evenings: six days in the scan's three weeks.
    $this->user = User::factory()->create([
        'home_location' => 'Springfield',
        'free_all_day' => [CarbonInterface::SATURDAY],
        'free_evenings' => [CarbonInterface::WEDNESDAY],
    ]);

    $this->calendar = Source::factory()->for($this->user)->create(['name' => 'City events', 'url' => 'https://city.example/events/{date:Y/m/d}']);
});

/** Each day's page of the city's calendar, answering with a status, and a reader that lists one show a day. */
function fakeCalendar(int $status = 200): void
{
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'city.example/events/*' => Http::response('<p>'.str_repeat('All events are free and open to the public. ', 6).'</p>', $status, ['Content-Type' => 'text/html']),
        'museum.example/calendar' => Http::response('<p>'.str_repeat('Open every day but Monday. ', 10).'</p>', 200, ['Content-Type' => 'text/html']),
    ]);

    PageReader::fake(function (string $prompt): array {
        if (! preg_match('#city\.example/events/(\d{4})/(\d{2})/(\d{2})#', $prompt, $date)) {
            return ['events' => [], 'note' => 'Nothing in range.'];
        }

        return ['events' => [['title' => 'Evening show', 'date' => "{$date[1]}-{$date[2]}-{$date[3]}", 'start_time' => '19:00', 'description' => 'A show.']], 'note' => 'One show.'];
    });
}

it('reads a page with one address for each day on each day of a scan they can go out, linking its events to that day\'s page', function () {
    fakeCalendar();
    EventScout::fake([['events' => []]]);

    $search = Search::discoveryFor($this->user);
    RunSearch::dispatch($search->id);

    $read = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->filter(fn (string $url): bool => str_contains($url, '/events/'))->values()->all();

    expect($read)->toBe([
        'https://city.example/events/2026/11/04',
        'https://city.example/events/2026/11/07',
        'https://city.example/events/2026/11/11',
        'https://city.example/events/2026/11/14',
        'https://city.example/events/2026/11/18',
        'https://city.example/events/2026/11/21',
    ]);

    PageReader::assertPrompted(fn ($prompt) => $prompt->contains('City events for Saturday, Nov 7: https://city.example/events/2026/11/07'));
    EventScout::assertPrompted(fn ($prompt) => $prompt->contains("City events: https://city.example/events/{date:Y/m/d} (a page for each day, read for you day by day)\n- 2026-11-04 · 19:00 · Evening show · https://city.example/events/2026/11/04 — A show.\n- 2026-11-07 · 19:00 · Evening show · https://city.example/events/2026/11/07 — A show.")
        && ! $prompt->contains('Fetch each one'));

    expect($this->calendar->refresh())
        ->page_read->toBeTrue()
        ->events_read->toBe(6)
        ->read_note->toBe('Read for each of its 6 days.')
        ->and($search->usages()->where('source_id', $this->calendar->id)->count())->toBe(6);
});

it('reads such a page on every day of an ad hoc search, for a month at most', function () {
    $search = Search::factory()->for($this->user)->create(['kind' => SearchKind::AdHoc, 'starts_on' => '2026-11-01', 'ends_on' => '2026-12-31']);

    $days = $search->daysToRead();

    expect($days)->toHaveCount(Source::MAX_DAYS)
        ->and($days[0]->toDateString())->toBe('2026-11-01')
        ->and($days[1]->toDateString())->toBe('2026-11-02');
});

it('reads a run\'s other pages first, and leaves out a calendar\'s last days when reading runs out of time', function () {
    $museum = Source::factory()->for($this->user)->create(['name' => 'Museum', 'url' => 'https://museum.example/calendar']);
    fakeCalendar();

    // Each read takes half a minute, so the first batch uses up all the time there is.
    PageReader::fake(function (string $prompt): array {
        Carbon::setTestNow(now()->addSeconds(30));

        return ['events' => [['title' => 'Evening show', 'date' => '2026-11-04', 'start_time' => '19:00']], 'note' => 'One show.'];
    });
    EventScout::fake([['events' => []]]);

    RunSearch::dispatch(Search::discoveryFor($this->user)->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://museum.example/calendar');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://city.example/events/2026/11/21');

    expect($museum->refresh()->events_read)->toBe(1)
        ->and($this->calendar->refresh())
        ->page_read->toBeTrue()
        ->events_read->toBe(RunSearch::PAGES_AT_ONCE - 1)
        ->read_note->toBe('Read for 5 of its 6 days.');
});

it('leaves a page with one address for each day to the scout for its first few days when Sunshine can\'t read it', function () {
    fakeCalendar(status: 403);
    EventScout::fake([['events' => []]]);

    RunSearch::dispatch(Search::discoveryFor($this->user)->id);

    EventScout::assertPrompted(fn ($prompt) => $prompt->contains("Fetch each one and include its events in the date range that they could go to. For each page, add a note to sources saying what you found there and why you left anything out:\n"
        ."- City events: https://city.example/events/2026/11/04 (Wednesday, Nov 4)\n"
        ."- City events: https://city.example/events/2026/11/07 (Saturday, Nov 7)\n"
        ."- City events: https://city.example/events/2026/11/11 (Wednesday, Nov 11)\n\n")
        && ! $prompt->contains('2026/11/14'));

    expect($this->calendar->refresh())
        ->page_read->toBeFalse()
        ->events_read->toBeNull()
        ->read_note->toBe('It answered Sunshine with 403.');
});

it('fills in the date in the form the address gives', function (string $url, string $filled) {
    expect(Source::fillDate($url, CarbonImmutable::parse('2026-11-07')))->toBe($filled);
})->with([
    'the date as YYYY-MM-DD' => ['https://city.example/events?day={date}', 'https://city.example/events?day=2026-11-07'],
    'its own form' => ['https://cityguide.example/events/{date:Y/n/j}', 'https://cityguide.example/events/2026/11/7'],
]);
