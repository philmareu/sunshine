<?php

use App\Ai\Agents\EventScout;
use App\Enums\EventStatus;
use App\Enums\SearchStatus;
use App\Jobs\RunSearch;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;

function foundEvent(array $overrides = []): array
{
    return [
        'title' => 'Home opener',
        'description' => 'The first home game of the season.',
        'date' => '2026-11-03',
        'time' => '7:00 PM',
        'venue' => 'Memorial Fieldhouse',
        'city' => 'Riverside',
        'region' => 'Oregon',
        'country' => 'us',
        'category' => 'Sports',
        'cost' => '$15',
        'url' => 'https://athletics.example.org/schedule',
        'reason' => 'You said you like college basketball.',
        'is_wildcard' => false,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'interests' => ['college basketball'],
        'avoid' => ['comedy'],
    ]);

    $this->search = Search::factory()->for($this->user)->create([
        'location' => 'Riverside',
        'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-08',
        'direction' => 'Something the kids can run around at',
    ]);
});

it('saves the events the scout finds and marks the search done', function () {
    EventScout::fake([['events' => [
        foundEvent(),
        foundEvent(['title' => 'Pottery for beginners', 'date' => '2026-11-05', 'category' => 'Class', 'is_wildcard' => true, 'time' => null]),
    ]]]);

    RunSearch::dispatch($this->search->id);

    expect($this->search->refresh()->status)->toBe(SearchStatus::Completed)
        ->and($this->search->events)->toHaveCount(2)
        ->and($this->search->events->firstWhere('title', 'Pottery for beginners'))
        ->is_wildcard->toBeTrue()
        ->starts_on->toDateString()->toBe('2026-11-05')
        ->city->toBe('Riverside')
        ->region->toBe('Oregon')
        ->country->toBe('US');

    EventScout::assertPrompted(fn ($prompt) => $prompt->contains('Riverside')
        && $prompt->contains('2026-11-01')
        && $prompt->contains('Something the kids can run around at'));
});

it('adds each event to the activity it is one time of', function () {
    $visit = Event::factory()->for(Search::factory()->for($this->user))->marked(EventStatus::Went)->create(['title' => 'Harvest festival', 'starts_on' => '2025-11-02']);
    $market = Activity::factory()->for($this->user)->create(['title' => 'Riverside Farmers Market', 'city' => 'Riverside']);
    $someoneElses = Activity::factory()->create();

    EventScout::fake([['events' => [
        foundEvent(['title' => 'Harvest Festival 2026', 'seen_before' => $visit->activity_id]),
        foundEvent(['title' => 'riverside  farmers market', 'date' => '2026-11-04', 'seen_before' => null]),
        foundEvent(['title' => 'Not theirs', 'seen_before' => $someoneElses->id]),
        foundEvent(['title' => 'Pottery night', 'date' => '2026-11-05']),
        foundEvent(['title' => 'Pottery night', 'date' => '2026-11-06']),
    ]]]);

    RunSearch::dispatch($this->search->id);

    $events = $this->search->events()->with('activity')->get()->groupBy('title');

    expect($events['Harvest Festival 2026']->sole()->activity_id)->toBe($visit->activity_id)
        ->and($events['riverside  farmers market']->sole()->activity_id)->toBe($market->id)
        ->and($events['Not theirs']->sole()->activity)
        ->id->not->toBe($someoneElses->id)
        ->user_id->toBe($this->user->id)
        ->and($events['Pottery night']->pluck('activity_id')->unique())->toHaveCount(1)
        ->and($events['Pottery night']->first()->activity)
        ->user_id->toBe($this->user->id)
        ->title->toBe('Pottery night')
        ->city->toBe('Riverside')
        ->category->toBe('Sports');

    expect((string) (new EventScout($this->user))->instructions())
        ->toContain("- #{$visit->activity_id}, 2025-11-02: \"Harvest festival\"");
});

it('drops events outside the date range or without a usable date', function () {
    EventScout::fake([['events' => [
        foundEvent(['title' => 'Too early', 'date' => '2026-10-31']),
        foundEvent(['title' => 'Too late', 'date' => '2026-11-09']),
        foundEvent(['title' => 'No date', 'date' => 'Saturday']),
        foundEvent(['title' => 'Last day', 'date' => '2026-11-08']),
    ]]]);

    RunSearch::dispatch($this->search->id);

    expect($this->search->events()->pluck('title')->all())->toBe(['Last day']);
});

it('drops events whose address is not a web page', function () {
    EventScout::fake([['events' => [
        foundEvent(['title' => 'Script', 'url' => 'javascript:alert(document.cookie)']),
        foundEvent(['title' => 'Data', 'url' => 'data:text/html,<script>alert(1)</script>']),
        foundEvent(['title' => 'Not an address', 'url' => 'the city website']),
        foundEvent(['title' => 'Plain web page', 'url' => 'http://parks.example.org/events']),
        foundEvent(['title' => 'Secure web page', 'url' => 'HTTPS://parks.example.org/fall']),
    ]]]);

    RunSearch::dispatch($this->search->id);

    expect($this->search->events()->orderBy('title')->pluck('title')->all())->toBe(['Plain web page', 'Secure web page']);
});

it('marks the search failed when the scout errors', function () {
    EventScout::fake(fn () => throw new RuntimeException('Provider unavailable'));

    try {
        RunSearch::dispatch($this->search->id);
    } catch (RuntimeException) {
        //
    }

    expect($this->search->refresh())
        ->status->toBe(SearchStatus::Failed)
        ->error->toBe('Provider unavailable');
});

it('briefs the scout with preferences, marked suggestions and how the things they went to turned out', function () {
    $past = Search::factory()->for($this->user)->create();
    $jazz = Event::factory()->for($past)->marked(EventStatus::Went)->create([
        'title' => 'Jazz in the park',
        'category' => 'Music',
        'starts_on' => '2026-10-18',
        'rating' => 5,
        'retrospective' => 'Great for a picnic. Get there early for shade.',
    ]);
    $pumpkins = Event::factory()->for($past)->marked(EventStatus::Interested)->create(['title' => 'Pumpkin patch', 'category' => 'Outdoors']);
    $standUp = Event::factory()->for($past)->marked(EventStatus::NotForUs)->create(['title' => 'Stand-up night', 'category' => 'Music', 'note' => 'Not kid friendly']);
    Event::factory()->for($past)->marked(EventStatus::Missed)->create(['title' => 'Rained out']);
    Event::factory()->for(Search::factory())->marked(EventStatus::Interested)->create(['title' => 'Someone else liked this']);

    $this->user->update(['budget' => 'Free or under $20 a person']);

    $instructions = (string) (new EventScout($this->user))->instructions();

    expect($instructions)
        ->toContain('They are interested in: college basketball')
        ->toContain('They want to avoid: comedy')
        ->toContain('- Their budget: Free or under $20 a person')
        ->toContain("- #{$pumpkins->activity_id}, Interested: \"Pumpkin patch\" (Outdoors)")
        ->toContain("- #{$standUp->activity_id}, Not for us: \"Stand-up night\" (Music) — Not kid friendly")
        ->toContain("- #{$jazz->activity_id}, 2026-10-18: \"Jazz in the park\" (Music), rated 5/5 — Great for a picnic. Get there early for shade.")
        ->not->toContain('Rained out')
        ->not->toContain('Someone else liked this');
});
