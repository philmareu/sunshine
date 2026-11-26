<?php

use App\Enums\EventStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\EventsRelationManager;
use App\Models\Activity;
use App\Models\ActivityNote;
use App\Models\ActivityPhoto;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create();
    $this->market = Activity::factory()->for($this->user)->create(['title' => 'Farmers market', 'description' => null]);

    $this->actingAs($this->user);
});

it('merges activities that turn out to be the same thing', function () {
    $saturday = Event::factory()->for($this->search)->for($this->market)->create(['starts_on' => '2026-11-07']);
    ChecklistItem::factory()->for($this->market)->create(['label' => 'Bring cash', 'sort' => 1]);

    $duplicate = Activity::factory()->for($this->user)->avoided('Too early in the morning.')->create(['title' => "Farmers' Market", 'description' => 'Local food every Saturday.']);
    $visit = Event::factory()->for($this->search)->for($duplicate)->marked(EventStatus::Went)->create(['starts_on' => '2026-10-24']);
    $note = ActivityNote::factory()->for($duplicate)->create();
    $photo = ActivityPhoto::factory()->for($duplicate)->for($visit)->create();
    $bags = ChecklistItem::factory()->for($duplicate)->create(['label' => 'Bring bags', 'sort' => 1]);

    Livewire::test(ViewActivity::class, ['record' => $this->market->getRouteKey()])
        ->callAction('merge', ['others' => [$duplicate->id]])
        ->assertHasNoActionErrors()
        ->assertRedirect(ActivityResource::getUrl('view', ['record' => $this->market]));

    expect(Activity::query()->find($duplicate->id))->toBeNull()
        ->and($this->market->events()->pluck('id')->sort()->values()->all())->toBe(collect([$saturday->id, $visit->id])->sort()->values()->all())
        ->and($note->refresh()->activity_id)->toBe($this->market->id)
        ->and($photo->refresh()->activity_id)->toBe($this->market->id)
        ->and($this->market->checklistItems()->pluck('label')->all())->toBe(['Bring cash', 'Bring bags'])
        ->and($bags->refresh()->sort)->toBe(2)
        ->and($this->market->refresh())
        ->title->toBe('Farmers market')
        ->description->toBe('Local food every Saturday.')
        ->isAvoided()->toBeTrue()
        ->avoid_reason->toBe('Too early in the morning.');
});

it('only merges your own activities', function () {
    $theirs = Activity::factory()->create();
    $theirEvent = Event::factory()->for(Search::factory()->for($theirs->user))->for($theirs)->create();

    Livewire::test(ViewActivity::class, ['record' => $this->market->getRouteKey()])
        ->callAction('merge', ['others' => [$theirs->id]]);

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirEvent->refresh()->activity_id)->toBe($theirs->id);
});

it('moves a date to the activity it really is, with its photos and a fresh checklist', function () {
    $craftFair = Activity::factory()->for($this->user)->create(['title' => 'Craft fair']);
    $cash = ChecklistItem::factory()->for($this->market)->create();
    Event::factory()->for($this->search)->for($this->market)->create(['starts_on' => '2026-11-07']);
    $wrong = Event::factory()->for($this->search)->for($this->market)->marked(EventStatus::Went)->create(['starts_on' => '2026-10-24', 'checked_items' => [$cash->id]]);
    $photo = ActivityPhoto::factory()->for($this->market)->for($wrong)->create();

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $this->market, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('move')->table($wrong), ['activity_id' => $craftFair->id])
        ->assertHasNoFormErrors()
        ->assertRedirect(ActivityResource::getUrl('view', ['record' => $this->market]));

    expect($wrong->refresh())
        ->activity_id->toBe($craftFair->id)
        ->checked_items->toBeNull()
        ->and($photo->refresh()->activity_id)->toBe($craftFair->id)
        ->and($this->market->fresh())->not->toBeNull();
});

it('moves a date to a new activity of its own, removing the one it leaves empty', function () {
    $only = Event::factory()->for($this->search)->for($this->market)->create(['starts_on' => '2026-11-07', 'city' => 'Riverside']);

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $this->market, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('move')->table($only), ['activity_id' => null, 'title' => 'Holiday craft market'])
        ->assertHasNoFormErrors();

    $activity = $only->refresh()->activity;

    expect($activity)
        ->title->toBe('Holiday craft market')
        ->city->toBe('Riverside')
        ->user_id->toBe($this->user->id)
        ->and($this->market->fresh())->toBeNull();
});
