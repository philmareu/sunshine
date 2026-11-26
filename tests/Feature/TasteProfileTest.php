<?php

use App\Ai\Agents\EventScout;
use App\Ai\Agents\TasteProfiler;
use App\Enums\EventStatus;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Jobs\RunSearch;
use App\Models\AiUsage;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;

beforeEach(function () {
    // Sunday, Nov 15, 2026.
    $this->travelTo('2026-11-15 09:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->scan = Search::factory()->for($this->user)->create([
        'kind' => SearchKind::Discovery,
        'location' => 'Springfield',
        'starts_on' => '2026-11-15',
        'ends_on' => '2026-12-06',
    ]);
    $this->past = Search::factory()->for($this->user)->create();

    EventScout::fake([['events' => [], 'sources' => []]]);
});

it('sums up their taste at the start of a scan, from what they marked and what they let pass, for the scout to read', function () {
    TasteProfiler::fake([['profile' => '- Outdoor things on weekend mornings are a yes.']]);

    $this->travelTo('2026-10-20 12:00:00');
    Event::factory()->for($this->past)->marked(EventStatus::Went)->create([
        'title' => 'Jazz in the park', 'category' => 'Music', 'city' => 'Riverton', 'starts_on' => '2026-10-17', 'time' => '7:00 PM',
        'rating' => 4, 'retrospective' => 'Great for a picnic.',
    ]);
    Event::factory()->for($this->past)->marked(EventStatus::NotForUs)->create(['title' => 'Stand-up night', 'note' => 'Not kid friendly']);
    Event::factory()->for($this->past)->marked(EventStatus::Interested)->create(['title' => 'Pumpkin patch', 'is_wildcard' => true]);
    Event::factory()->for($this->past)->marked(EventStatus::Missed)->create(['title' => 'Rained out']);
    Event::factory()->for($this->past)->create(['title' => 'Organ recital']);
    Event::factory()->for($this->past)->create(['title' => 'Busy that day', 'hidden_at' => now()]);
    Event::factory()->for(Search::factory())->marked(EventStatus::Went)->create(['title' => 'Someone else went']);
    $this->travelBack();
    $this->travelTo('2026-11-15 09:00:00');
    Event::factory()->for($this->past)->create(['title' => 'Found this week']);

    RunSearch::dispatch($this->scan->id);

    TasteProfiler::assertPrompted(fn ($prompt) => $prompt->contains('- Sat 2026-10-17 · 7:00 PM · Music · Riverton · "Jazz in the park" — Went, rated 4/5: Great for a picnic.')
        && $prompt->contains('"Stand-up night" — Not for us: Not kid friendly')
        && $prompt->contains('"Pumpkin patch" (wildcard) — Interested')
        && $prompt->contains("Suggestions they left unmarked for 14 days or more, newest first:\n- ")
        && $prompt->contains('"Organ recital"')
        && ! $prompt->contains('Rained out')
        && ! $prompt->contains('Busy that day')
        && ! $prompt->contains('Found this week')
        && ! $prompt->contains('Someone else went'));

    expect($this->user->refresh())
        ->taste_profile->toBe('- Outdoor things on weekend mornings are a yes.')
        ->taste_profiled_at->toDateTimeString()->toBe('2026-11-15 09:00:00')
        ->and($this->scan->refresh()->status)->toBe(SearchStatus::Completed);

    expect(AiUsage::query()->where('purpose', AiUsage::TASTE_PROFILE)->sole())
        ->user_id->toBe($this->user->id)
        ->search_id->toBeNull()
        ->location->toBe('Springfield');

    expect((string) (new EventScout($this->user))->instructions())
        ->toContain("Find more of what they say yes to, and steer away from what they skip:\n- Outdoor things on weekend mornings are a yes.")
        ->toContain('Suggestions they marked recently, newest first, so you know them when they come around again.')
        ->not->toContain('Find more like the ones they were interested in');
});

it('writes it once a week, on the scan near home only', function () {
    TasteProfiler::fake([['profile' => '- A fresh summary.']]);

    Event::factory()->for($this->past)->marked(EventStatus::Interested)->create();
    $this->user->forceFill(['taste_profile' => '- Last week\'s summary.', 'taste_profiled_at' => now()->subDays(3)])->save();

    RunSearch::dispatch($this->scan->id);
    RunSearch::dispatch($this->scan->id, ['location' => 'Bayport, WA']);
    RunSearch::dispatch(Search::factory()->for($this->user)->create()->id);

    TasteProfiler::assertNeverPrompted();

    $this->travel(3)->days();
    RunSearch::dispatch($this->scan->id, ['location' => 'Bayport, WA']);

    TasteProfiler::assertNeverPrompted();

    RunSearch::dispatch($this->scan->id);

    expect($this->user->refresh()->taste_profile)->toBe('- A fresh summary.');
});

it('has nothing to sum up until they mark something', function () {
    TasteProfiler::fake();

    Event::factory()->for($this->past)->marked(EventStatus::Missed)->create();

    RunSearch::dispatch($this->scan->id);

    TasteProfiler::assertNeverPrompted();

    expect($this->user->refresh()->taste_profile)->toBeNull()
        ->and((string) (new EventScout($this->user))->instructions())->not->toContain('How their taste runs');
});

it('keeps last week\'s summary and carries on with the scan when writing a new one fails', function () {
    TasteProfiler::fake(fn () => throw new RuntimeException('Provider unavailable'));

    Event::factory()->for($this->past)->marked(EventStatus::Interested)->create();
    $this->user->forceFill(['taste_profile' => '- Last week\'s summary.', 'taste_profiled_at' => now()->subDays(8)])->save();

    RunSearch::dispatch($this->scan->id);

    expect($this->user->refresh())
        ->taste_profile->toBe('- Last week\'s summary.')
        ->taste_profiled_at->toDateTimeString()->toBe('2026-11-07 09:00:00')
        ->and($this->scan->refresh()->status)->toBe(SearchStatus::Completed);
});
