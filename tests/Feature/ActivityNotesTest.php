<?php

use App\Ai\Agents\EventScout;
use App\Enums\EventStatus;
use App\Filament\Pages\Plans;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\NotesRelationManager;
use App\Models\Activity;
use App\Models\ActivityNote;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    // Sunday, Nov 1, 2026.
    $this->travelTo('2026-11-01 15:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create();
    $this->activity = Activity::factory()->for($this->user)->create(['title' => 'Harvest festival']);

    $this->actingAs($this->user);
});

it('adds, edits and deletes notes on an activity', function () {
    $component = Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('create')->table(), ['body' => 'Get there before 9, parking fills up.'])
        ->assertHasNoFormErrors();

    $note = $this->activity->notes()->sole();

    expect($note->body)->toBe('Get there before 9, parking fills up.');

    $component
        ->assertCanSeeTableRecords([$note])
        ->callAction(TestAction::make('edit')->table($note), ['body' => 'Get there before 8.'])
        ->assertHasNoFormErrors();

    expect($note->refresh()->body)->toBe('Get there before 8.');

    $component->callAction(TestAction::make('delete')->table($note));

    expect($this->activity->notes()->exists())->toBeFalse();
});

it('needs something written', function () {
    Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('create')->table(), ['body' => ''])
        ->assertHasFormErrors(['body' => 'required']);
});

it('shows the newest note on the activity\'s event cards', function () {
    ActivityNote::factory()->for($this->activity)->create(['body' => 'Bring cash for the hayride.']);
    ActivityNote::factory()->for($this->activity)->create(['body' => 'Get there before 9, parking fills up.']);
    $event = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-08']);

    Livewire::test(Plans::class)
        ->assertCanSeeTableRecords([$event])
        ->assertSee('Get there before 9, parking fills up.')
        ->assertSee('One more note')
        ->assertDontSee('Bring cash for the hayride.');
});

it('tells the scout the notes on the things it knows about', function () {
    Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02']);
    ActivityNote::factory()->for($this->activity)->create(['body' => "Kids loved the hayride.\nSkip the maze."]);
    ActivityNote::factory()->for($this->activity)->create(['body' => 'Get there before 9.']);
    ActivityNote::factory()->for(Activity::factory()->for($this->user))->create(['body' => 'Not one the scout knows about.']);

    expect((string) (new EventScout($this->user))->instructions())
        ->toContain("- #{$this->activity->id}: Kids loved the hayride. Skip the maze. / Get there before 9.")
        ->not->toContain('Not one the scout knows about.');
});

it('keeps an activity with notes when the search it came up in is deleted', function () {
    $event = Event::factory()->for($this->search)->create();
    ActivityNote::factory()->for($event->activity)->create();

    $this->search->delete();

    expect($event->activity->fresh())->not->toBeNull();
});
