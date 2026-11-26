<?php

use App\Enums\EventStatus;
use App\Filament\Pages\History;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\PhotosRelationManager;
use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // No time travel here: Livewire's fake uploads fail validation after travelTo().
    Storage::fake('local');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create();
    $this->activity = Activity::factory()->for($this->user)->create(['title' => 'Harvest festival']);

    $this->actingAs($this->user);
});

it('adds photos to an activity, from its last visit unless you say otherwise', function () {
    $earlier = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2024-10-26']);
    $visit = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02']);

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('upload')->table(), ['photos' => [UploadedFile::fake()->image('hayride.jpg'), UploadedFile::fake()->image('maze.jpg')]])
        ->assertHasNoFormErrors();

    $photos = $this->activity->photos()->get();

    expect($photos)->toHaveCount(2)
        ->and($photos->pluck('event_id')->unique()->all())->toBe([$visit->id]);

    $photos->each(fn (ActivityPhoto $photo) => Storage::disk('local')->assertExists($photo->path));

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('upload')->table(), [
            'photos' => [UploadedFile::fake()->image('two-years-ago.jpg')],
            'event_id' => $earlier->id,
        ])
        ->assertHasNoFormErrors();

    expect($earlier->photos()->count())->toBe(1);
});

it('only takes images', function () {
    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->callAction(TestAction::make('upload')->table(), ['photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]])
        ->assertHasFormErrors(['photos']);

    expect($this->activity->photos()->exists())->toBeFalse();
});

it('captions a photo and deletes it along with its file', function () {
    Storage::disk('local')->put('activity-photos/hayride.jpg', 'photo');
    $photo = ActivityPhoto::factory()->for($this->activity)->create(['path' => 'activity-photos/hayride.jpg']);

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->assertCanSeeTableRecords([$photo])
        ->callAction(TestAction::make('edit')->table($photo), ['caption' => 'On the hayride'])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('delete')->table($photo));

    expect(ActivityPhoto::query()->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('activity-photos/hayride.jpg');
});

it('shows a photo from the visit on its card in History', function () {
    $visit = Event::factory()->for($this->search)->for($this->activity)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02']);
    ActivityPhoto::factory()->for($this->activity)->for($visit)->create(['path' => 'activity-photos/hayride.jpg']);

    Livewire::test(History::class)
        ->assertCanSeeTableRecords([$visit])
        ->assertTableColumnStateSet('photo', 'activity-photos/hayride.jpg', $visit);
});

it('does not show another user\'s photos', function () {
    $theirs = ActivityPhoto::factory()->create();

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $this->activity, 'pageClass' => ViewActivity::class])
        ->assertCanNotSeeTableRecords([$theirs]);
});
