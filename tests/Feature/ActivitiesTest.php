<?php

use App\Enums\EventStatus;
use App\Filament\Pages\Plans;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\EventsRelationManager;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\EditAction;
use Livewire\Livewire;

beforeEach(function () {
    // Sunday, Nov 1, 2026.
    $this->travelTo('2026-11-01 15:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create();
    $this->activity = Activity::factory()->for($this->user)->create(['title' => 'Harvest festival']);

    $this->actingAs($this->user);
});

it('lists your activities, with a tab for the ones you went to', function () {
    Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02', 'rating' => 4]);
    Event::factory()->for($this->search)->for($this->activity)->create(['starts_on' => '2026-11-08']);
    $unmarked = Event::factory()->for($this->search)->create()->activity;
    $someoneElses = Activity::factory()->create();

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$this->activity, $unmarked])
        ->assertCanNotSeeTableRecords([$someoneElses])
        ->assertTableColumnStateSet('events_count', 2, $this->activity)
        ->assertTableColumnStateSet('visits_count', 1, $this->activity)
        ->set('activeTab', EventStatus::Went->value)
        ->assertCanSeeTableRecords([$this->activity])
        ->assertCanNotSeeTableRecords([$unmarked]);

    $this->get(ActivityResource::getUrl('view', ['record' => $someoneElses]))->assertNotFound();
});

it('shows every time an activity came up, newest first, and marks them from there', function () {
    $visit = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02', 'rating' => 5]);
    $again = Event::factory()->for($this->search)->for($this->activity)->create(['starts_on' => '2026-11-08']);
    $other = Event::factory()->for($this->search)->create();

    $this->get(ActivityResource::getUrl('view', ['record' => $this->activity]))
        ->assertOk()
        ->assertSee('Harvest festival')
        ->assertSee('2 times')
        ->assertSee('Once · ★★★★★');

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->assertCanSeeTableRecords([$again, $visit], inOrder: true)
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableActionHidden('activity', $again)
        ->callTableAction('interested', $again);

    expect($again->refresh()->status)->toBe(EventStatus::Interested);
});

it('renames an activity', function () {
    Livewire::test(ViewActivity::class, ['record' => $this->activity->getRouteKey()])
        ->callAction(EditAction::class, ['title' => 'Harvest Festival at the farm'])
        ->assertHasNoActionErrors();

    expect($this->activity->refresh()->title)->toBe('Harvest Festival at the farm');
});

it('links each event card to its activity', function () {
    $event = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-08']);

    Livewire::test(Plans::class)
        ->assertTableActionHasUrl('activity', ActivityResource::getUrl('view', ['record' => $this->activity]), $event);
});

it('drops activities that only came up in a deleted search', function () {
    $onlyHere = Event::factory()->for($this->search)->create()->activity;
    Event::factory()->for($this->search)->for($this->activity)->create();
    Event::factory()->for(Search::factory()->for($this->user))->for($this->activity)->create();

    $this->search->delete();

    expect(Activity::query()->find($onlyHere->id))->toBeNull()
        ->and($this->activity->fresh())->not->toBeNull();
});
