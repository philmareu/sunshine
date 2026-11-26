<?php

use App\Ai\Agents\EventScout;
use App\Enums\EventStatus;
use App\Filament\Pages\Upcoming;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Jobs\RunSearch;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    // Sunday, Nov 1, 2026.
    $this->travelTo('2026-11-01 15:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->scan = Search::factory()->for($this->user)->create(['kind' => 'discovery', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-22']);
    $this->activity = Activity::factory()->for($this->user)->create(['title' => 'Monster truck rally', 'city' => 'Springfield']);

    $this->actingAs($this->user);
});

it('avoids an activity from its page, and takes it back', function () {
    Livewire::test(ViewActivity::class, ['record' => $this->activity->getRouteKey()])
        ->assertActionHidden('stop_avoiding')
        ->callAction('avoid', ['reason' => 'Far too loud for the little one.'])
        ->assertHasNoActionErrors();

    expect($this->activity->refresh())
        ->isAvoided()->toBeTrue()
        ->avoid_reason->toBe('Far too loud for the little one.');

    Livewire::test(ListActivities::class)
        ->set('activeTab', 'avoided')
        ->assertCanSeeTableRecords([$this->activity]);

    Livewire::test(ViewActivity::class, ['record' => $this->activity->getRouteKey()])
        ->assertActionHidden('avoid')
        ->callAction('stop_avoiding');

    expect($this->activity->refresh())
        ->isAvoided()->toBeFalse()
        ->avoid_reason->toBeNull();
});

it('avoids the whole activity from "Not for us", hiding its other dates from Upcoming', function () {
    $thisWeek = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-07']);
    $nextWeek = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-14']);
    $other = Event::factory()->for($this->scan)->create(['starts_on' => '2026-11-07']);

    Livewire::test(Upcoming::class)
        ->callAction(TestAction::make('not_for_us')->table($thisWeek), ['note' => 'Far too loud.', 'avoid' => true])
        ->assertHasNoFormErrors();

    expect($thisWeek->refresh()->status)->toBe(EventStatus::NotForUs)
        ->and($this->activity->refresh())
        ->isAvoided()->toBeTrue()
        ->avoid_reason->toBe('Far too loud.');

    Livewire::test(Upcoming::class)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$thisWeek, $nextWeek])
        ->filterTable('hide_not_for_us', false)
        ->assertCanSeeTableRecords([$thisWeek, $nextWeek, $other]);
});

it('marks one date "Not for us" without avoiding the activity', function () {
    $thisWeek = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-07']);

    Livewire::test(Upcoming::class)
        ->callAction(TestAction::make('not_for_us')->table($thisWeek), ['note' => 'Busy that day.', 'avoid' => false]);

    expect($this->activity->refresh()->isAvoided())->toBeFalse();
});

it('offers to mark the same thing\'s other dates to come, and ones like it, "Not for us" too', function () {
    $gymnastics = Activity::factory()->for($this->user)->create(['title' => 'Gymnastics 1', 'city' => 'Maple Falls']);
    $event = Event::factory()->for($this->scan)->for($gymnastics)->create(['title' => 'Gymnastics 1', 'city' => 'Maple Falls', 'starts_on' => '2026-11-03']);
    $nextWeek = Event::factory()->for($this->scan)->for($gymnastics)->create(['title' => 'Gymnastics 1', 'city' => 'Maple Falls', 'starts_on' => '2026-11-10']);
    $nextLevel = Event::factory()->for($this->scan)->create(['title' => 'Gymnastics 2', 'city' => 'Maple Falls', 'starts_on' => '2026-11-05']);

    // Already gone, already marked, elsewhere, something else, and someone else's.
    Event::factory()->for($this->scan)->for($gymnastics)->create(['title' => 'Gymnastics 1', 'city' => 'Maple Falls', 'starts_on' => '2026-10-27']);
    Event::factory()->for($this->scan)->for($gymnastics)->marked(EventStatus::Interested)->create(['title' => 'Gymnastics 1', 'city' => 'Maple Falls', 'starts_on' => '2026-11-17']);
    Event::factory()->for($this->scan)->create(['title' => 'Gymnastics 2', 'city' => 'Riverton', 'starts_on' => '2026-11-05']);
    Event::factory()->for($this->scan)->create(['title' => 'Gymnastics and Dance', 'city' => 'Maple Falls', 'starts_on' => '2026-11-05']);
    Event::factory()->for(Search::factory()->create(['kind' => 'discovery']))->create(['title' => 'Gymnastics 2', 'city' => 'Maple Falls', 'starts_on' => '2026-11-05']);

    Livewire::test(Upcoming::class)
        ->mountAction(TestAction::make('not_for_us')->table($event))
        ->assertSchemaStateSet(['related' => [$nextLevel->id, $nextWeek->id]]);
});

it('marks the ones left ticked "Not for us" with the same note', function () {
    $event = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-07']);
    $nextWeek = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-14']);
    $weekAfter = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-21']);

    Livewire::test(Upcoming::class)
        ->callAction(TestAction::make('not_for_us')->table($event), ['note' => 'Far too loud.', 'related' => [$nextWeek->id]])
        ->assertHasNoFormErrors();

    expect($nextWeek->refresh())
        ->status->toBe(EventStatus::NotForUs)
        ->note->toBe('Far too loud.')
        ->marked_at->not->toBeNull()
        ->and($weekAfter->refresh()->status)->toBeNull();
});

it('leaves the rest unticked when changing the note on one already marked', function () {
    $event = Event::factory()->for($this->scan)->for($this->activity)->marked(EventStatus::NotForUs)->create(['starts_on' => '2026-11-07']);
    $nextWeek = Event::factory()->for($this->scan)->for($this->activity)->create(['starts_on' => '2026-11-14']);

    Livewire::test(Upcoming::class)
        ->filterTable('hide_not_for_us', false)
        ->mountAction(TestAction::make('not_for_us')->table($event))
        ->assertSchemaStateSet(['related' => []])
        ->callMountedAction();

    expect($nextWeek->refresh()->status)->toBeNull();
});

it('leaves avoided activities out of new scans and tells the scout about them', function () {
    $this->activity->avoid('Far too loud.');
    $renamed = Activity::factory()->for($this->user)->avoided()->create(['title' => 'Haunted maze']);

    EventScout::fake([['events' => [
        ['title' => 'Monster Truck Rally', 'city' => 'Springfield', 'date' => '2026-11-07', 'url' => 'https://example.org/trucks', 'seen_before' => null],
        ['title' => 'Haunted Maze: Final Weekend', 'city' => 'Springfield', 'date' => '2026-11-07', 'url' => 'https://example.org/maze', 'seen_before' => $renamed->id],
        ['title' => 'Pottery night', 'city' => 'Springfield', 'date' => '2026-11-07', 'url' => 'https://example.org/pottery', 'seen_before' => null],
    ]]]);

    RunSearch::dispatch($this->scan->id);

    expect($this->scan->events()->pluck('title')->all())->toBe(['Pottery night']);

    expect((string) (new EventScout($this->user))->instructions())
        ->toContain('- "Monster truck rally" in Springfield — Far too loud.')
        ->toContain('- "Haunted maze"');
});
