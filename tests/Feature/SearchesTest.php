<?php

use App\Enums\EventStatus;
use App\Enums\SearchStatus;
use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Filament\Resources\Searches\Pages\ViewSearch;
use App\Filament\Resources\Searches\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Searches\RelationManagers\SourcesRelationManager;
use App\Jobs\RunSearch;
use App\Models\Event;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['home_location' => 'Springfield']);

    $this->actingAs($this->user);
});

it('starts a search at the home location and queues the scout', function () {
    Queue::fake();

    Livewire::test(CreateSearch::class)
        ->assertSchemaStateSet(['location' => 'Springfield'])
        ->fillForm([
            'location' => 'Riverside',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-08',
            'direction' => 'Something indoors',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $search = Search::query()->sole();

    expect($search)
        ->user_id->toBe($this->user->id)
        ->location->toBe('Riverside')
        ->status->toBe(SearchStatus::Pending);

    Queue::assertPushed(RunSearch::class, fn (RunSearch $job) => $job->searchId === $search->id);
});

it('saves pages to check for that search only', function () {
    Queue::fake([RunSearch::class]);

    Livewire::test(CreateSearch::class)
        ->fillForm([
            'location' => 'Austin, TX',
            'starts_on' => '2026-11-06',
            'ends_on' => '2026-11-08',
            'sources' => [['url' => 'https://festival.example.org/schedule', 'name' => 'Festival']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Search::query()->sole()->sources()->sole())
        ->user_id->toBe($this->user->id)
        ->url->toBe('https://festival.example.org/schedule');
    expect($this->user->watchedSources()->count())->toBe(0);
});

it('shows what the scout made of a search\'s pages, only when it has some', function () {
    $search = Search::factory()->for($this->user)->create(['status' => SearchStatus::Completed]);

    expect(SourcesRelationManager::canViewForRecord($search, ViewSearch::class))->toBeFalse();

    $schedule = Source::factory()->for($this->user)->for($search)->checked('Two shows in range.')->create();

    expect(SourcesRelationManager::canViewForRecord($search, ViewSearch::class))->toBeTrue();

    Livewire::test(SourcesRelationManager::class, ['ownerRecord' => $search, 'pageClass' => ViewSearch::class])
        ->assertCanSeeTableRecords([$schedule])
        ->assertSee('Two shows in range.');
});

it('rejects an end date before the start date', function () {
    Queue::fake();

    Livewire::test(CreateSearch::class)
        ->fillForm(['location' => 'Riverside', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-01'])
        ->call('create')
        ->assertHasFormErrors(['ends_on']);

    Queue::assertNothingPushed();
});

it('only lists your own searches', function () {
    $mine = Search::factory()->for($this->user)->create();
    $theirs = Search::factory()->create();

    Livewire::test(ListSearches::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    $this->get(route('filament.app.resources.searches.view', $theirs))->assertNotFound();
});

it('records what didn\'t work about an event', function () {
    $search = Search::factory()->for($this->user)->create(['status' => SearchStatus::Completed]);
    $event = Event::factory()->for($search)->create();

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $search, 'pageClass' => ViewSearch::class])
        ->assertCanSeeTableRecords([$event])
        ->callTableAction('not_for_us', $event, ['note' => 'Too late for the kids'])
        ->assertHasNoTableActionErrors();

    expect($event->refresh())
        ->status->toBe(EventStatus::NotForUs)
        ->note->toBe('Too late for the kids')
        ->marked_at->not->toBeNull();
});

it('sorts a search\'s events into tabs by how they were marked', function () {
    $search = Search::factory()->for($this->user)->create(['status' => SearchStatus::Completed]);
    $unmarked = Event::factory()->for($search)->create();
    $interested = Event::factory()->for($search)->marked(EventStatus::Interested)->create();
    $went = Event::factory()->for($search)->marked(EventStatus::Went)->create();

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $search, 'pageClass' => ViewSearch::class])
        ->assertCanSeeTableRecords([$unmarked, $interested, $went])
        ->set('activeTab', 'interested')
        ->assertCanSeeTableRecords([$interested])
        ->assertCanNotSeeTableRecords([$unmarked, $went])
        ->set('activeTab', 'went')
        ->assertCanSeeTableRecords([$went])
        ->assertCanNotSeeTableRecords([$unmarked, $interested]);
});

it('keeps a search when you marked any of its events', function () {
    $kept = Search::factory()->for($this->user)->create(['status' => SearchStatus::Completed]);
    Event::factory()->for($kept)->marked(EventStatus::Went)->create();
    $unmarked = Search::factory()->for($this->user)->create(['status' => SearchStatus::Completed]);
    Event::factory()->for($unmarked)->create();

    Livewire::test(ListSearches::class)
        ->assertTableActionDisabled('delete', $kept)
        ->callTableAction('delete', $unmarked);

    expect($unmarked->fresh())->toBeNull()
        ->and($kept->delete())->toBeFalse()
        ->and($kept->fresh())->not->toBeNull();
});

it('retries a failed search', function () {
    Queue::fake();

    $search = Search::factory()->for($this->user)->create(['status' => SearchStatus::Failed, 'error' => 'Timed out']);

    Livewire::test(ViewSearch::class, ['record' => $search->getRouteKey()])
        ->callAction('retry');

    expect($search->refresh())
        ->status->toBe(SearchStatus::Pending)
        ->error->toBeNull();

    Queue::assertPushed(RunSearch::class);
});
