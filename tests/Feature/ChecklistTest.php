<?php

use App\Enums\EventStatus;
use App\Filament\Pages\Plans;
use App\Filament\Pages\Upcoming;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\ChecklistItemsRelationManager;
use App\Models\Activity;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    // Sunday, Nov 1, 2026.
    $this->travelTo('2026-11-01 15:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $this->activity = Activity::factory()->for($this->user)->create(['title' => 'Harvest festival']);

    $this->actingAs($this->user);
});

it('adds items to the end of an activity\'s checklist', function () {
    ChecklistItem::factory()->for($this->activity)->create(['label' => 'Bring cash', 'sort' => 1]);

    Livewire::test(ChecklistItemsRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('create')->table(), ['label' => 'Sunscreen'])
        ->assertHasNoFormErrors();

    expect($this->activity->checklistItems()->pluck('label')->all())->toBe(['Bring cash', 'Sunscreen']);
});

it('ticks off the checklist for each planned visit on its own', function () {
    $cash = ChecklistItem::factory()->for($this->activity)->create(['label' => 'Bring cash', 'sort' => 1]);
    ChecklistItem::factory()->for($this->activity)->create(['label' => 'Sunscreen', 'sort' => 2]);
    $saturday = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-07']);
    $nextSaturday = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-14']);

    Livewire::test(Plans::class)
        ->callAction(TestAction::make('checklist')->table($saturday), ['checked_items' => [(string) $cash->id]])
        ->assertHasNoFormErrors();

    expect($saturday->refresh()->checked_items)->toBe([$cash->id])
        ->and($nextSaturday->refresh()->checked_items)->toBeNull();

    Livewire::test(Plans::class)
        ->assertActionExists(TestAction::make('checklist')->table($saturday), fn (Action $action): bool => $action->getBadge() === '1/2')
        ->assertActionExists(TestAction::make('checklist')->table($nextSaturday), fn (Action $action): bool => $action->getBadge() === '0/2');
});

it('only offers the checklist on things you are interested in, when the activity has one', function () {
    ChecklistItem::factory()->for($this->activity)->create();
    $unmarked = Event::factory()->for($this->search)->for($this->activity)->create(['starts_on' => '2026-11-07']);
    $interested = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-14']);
    $noChecklist = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-14']);

    Livewire::test(Upcoming::class)
        ->removeTableFilter('hide_interested')
        ->assertActionHidden(TestAction::make('checklist')->table($unmarked))
        ->assertActionVisible(TestAction::make('checklist')->table($interested))
        ->assertActionHidden(TestAction::make('checklist')->table($noChecklist));
});
