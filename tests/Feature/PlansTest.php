<?php

use App\Enums\EventStatus;
use App\Filament\Pages\History;
use App\Filament\Pages\Plans;
use App\Filament\Pages\Upcoming;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Livewire\Livewire;

beforeEach(function () {
    // Sunday, Nov 1, 2026.
    $this->travelTo('2026-11-01 15:00:00');

    $this->user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($this->user)->create();

    $this->actingAs($this->user);
});

it('marks an event interested from Upcoming, which moves it to your plans', function () {
    $event = Event::factory()->for(Search::factory()->for($this->user)->create(['kind' => 'discovery']))->create(['starts_on' => '2026-11-07']);

    Livewire::test(Upcoming::class)
        ->callTableAction('interested', $event)
        ->assertNotified('Added to Plans')
        ->assertCanNotSeeTableRecords([$event])
        ->removeTableFilter('hide_interested')
        ->assertCanSeeTableRecords([$event]);

    expect($event->refresh())
        ->status->toBe(EventStatus::Interested)
        ->marked_at->not->toBeNull();

    Livewire::test(Plans::class)->assertCanSeeTableRecords([$event]);
});

it('gathers everything you are interested in from scans and searches, asking about the ones that have passed first', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $passed = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-30']);
    $soon = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-03']);
    $later = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-12']);
    $unmarked = Event::factory()->for($this->search)->create(['starts_on' => '2026-11-04']);
    $went = Event::factory()->for($this->search)->marked(EventStatus::Went)->create(['starts_on' => '2026-10-29']);
    $someoneElses = Event::factory()->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-03']);

    Livewire::test(Plans::class)
        ->assertSee('One has been and gone: did you go?')
        ->assertCanSeeTableRecords([$passed, $soon, $later], inOrder: true)
        ->assertCanNotSeeTableRecords([$unmarked, $went, $someoneElses])
        ->assertTableActionVisible('went', $passed)
        ->assertTableActionVisible('missed', $passed)
        ->assertTableActionHidden('interested', $passed)
        ->assertTableActionVisible('interested', $soon)
        ->assertTableActionHidden('went', $soon)
        ->assertTableActionHidden('missed', $soon);
});

it('takes a plan back with a second click', function () {
    $event = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-07']);

    Livewire::test(Plans::class)->callTableAction('interested', $event);

    expect($event->refresh()->status)->toBeNull();
});

it('takes an event you can\'t make off Upcoming and Plans, leaving its mark for the scout as it was', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $busy = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-07']);
    $free = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-08']);

    Livewire::test(Plans::class)->callTableAction('cant_make_it', $busy);

    expect($busy->refresh())
        ->hidden_at->not->toBeNull()
        ->status->toBe(EventStatus::Interested);

    Livewire::test(Upcoming::class)
        ->removeTableFilter('hide_interested')
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$busy])
        ->removeTableFilter('hide_cant_make_it')
        ->assertCanSeeTableRecords([$busy, $free])
        ->assertSee('Can\'t make it');

    Livewire::test(Plans::class)
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$busy]);
});

it('puts an event you can\'t make back with a second click, or when you mark it interested', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $undone = Event::factory()->for($scan)->create(['starts_on' => '2026-11-07', 'hidden_at' => now()]);
    $wanted = Event::factory()->for($scan)->create(['starts_on' => '2026-11-08', 'hidden_at' => now()]);

    Livewire::test(Upcoming::class)
        ->removeTableFilter('hide_cant_make_it')
        ->callTableAction('cant_make_it', $undone)
        ->callTableAction('interested', $wanted);

    expect($undone->refresh()->hidden_at)->toBeNull()
        ->and($wanted->refresh())
        ->hidden_at->toBeNull()
        ->status->toBe(EventStatus::Interested);
});

it('marks an event interested and can\'t make it in one click, so the scout learns from it but it stays off Upcoming and Plans', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $event = Event::factory()->for($scan)->create(['starts_on' => '2026-11-07']);

    Livewire::test(Upcoming::class)
        ->callTableAction('interested_cant_make_it', $event)
        ->assertNotified('Noted: you\'d like it, but can\'t make it')
        ->assertCanNotSeeTableRecords([$event]);

    expect($event->refresh())
        ->status->toBe(EventStatus::Interested)
        ->marked_at->not->toBeNull()
        ->hidden_at->not->toBeNull();

    Livewire::test(Plans::class)->assertCanNotSeeTableRecords([$event]);
});

it('doesn\'t offer interested-but-can\'t-make-it once it is marked interested, where can\'t make it does the same', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $soon = Event::factory()->for($scan)->create(['starts_on' => '2026-11-03']);
    $planned = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-03']);

    Livewire::test(Upcoming::class)
        ->removeTableFilter('hide_interested')
        ->assertTableActionVisible('interested_cant_make_it', $soon)
        ->assertTableActionHidden('interested_cant_make_it', $planned);
});

it('takes everything on the days you pick off Upcoming and Plans, leaving each mark as it was', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    $found = Event::factory()->for($scan)->create(['starts_on' => '2026-11-07']);
    $planned = Event::factory()->for($scan)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-07']);
    $travel = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-08']);
    $alsoBusy = Event::factory()->for($scan)->create(['starts_on' => '2026-11-08']);
    $free = Event::factory()->for($scan)->create(['starts_on' => '2026-11-09']);
    $unpicked = Event::factory()->for($this->search)->create(['starts_on' => '2026-11-07']);

    Livewire::test(Upcoming::class)
        ->mountAction('busy_days')
        ->assertFormFieldExists('days', fn (CheckboxList $field): bool => $field->getOptions() === [
            '2026-11-07' => 'Sat, Nov 7 (2 events)',
            '2026-11-08' => 'Sun, Nov 8 (2 events)',
            '2026-11-09' => 'Mon, Nov 9 (1 event)',
        ])
        ->setActionData(['days' => ['2026-11-07', '2026-11-08']])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Took 4 events off')
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$found, $alsoBusy]);

    expect([$found->refresh(), $planned->refresh(), $travel->refresh(), $alsoBusy->refresh()])
        ->each(fn ($event) => $event->hidden_at->not->toBeNull())
        ->and($planned->status)->toBe(EventStatus::Interested)
        ->and($found->status)->toBeNull()
        ->and($free->refresh()->hidden_at)->toBeNull()
        ->and($unpicked->refresh()->hidden_at)->toBeNull();

    Livewire::test(Plans::class)->assertCanNotSeeTableRecords([$planned, $travel]);
});

it('only offers busy days from today on that still have something on them', function () {
    $scan = Search::factory()->for($this->user)->create(['kind' => 'discovery']);
    Event::factory()->for($scan)->create(['starts_on' => '2026-10-31']);
    Event::factory()->for($scan)->create(['starts_on' => '2026-11-01']);
    Event::factory()->for($scan)->create(['starts_on' => '2026-11-02', 'hidden_at' => now()]);

    Livewire::test(Upcoming::class)
        ->mountAction('busy_days')
        ->assertFormFieldExists('days', fn (CheckboxList $field): bool => $field->getOptions() === ['2026-11-01' => 'Sun, Nov 1 (1 event)'])
        ->setActionData(['days' => []])
        ->callMountedAction()
        ->assertHasActionErrors(['days' => 'required']);
});

it('only lets you say you can\'t make something that hasn\'t happened yet', function () {
    $passed = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-30']);
    $soon = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-03']);

    Livewire::test(Plans::class)
        ->assertTableActionHidden('cant_make_it', $passed)
        ->assertTableActionVisible('cant_make_it', $soon);
});

it('records that you went and how it was, and keeps it in your history', function () {
    $event = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-31']);

    Livewire::test(Plans::class)
        ->callTableAction('went', $event, ['rating' => 4, 'retrospective' => 'Fun, but get there before the crowds.'])
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$event]);

    expect($event->refresh())
        ->status->toBe(EventStatus::Went)
        ->rating->toBe(4)
        ->retrospective->toBe('Fun, but get there before the crowds.');

    Livewire::test(History::class)
        ->assertCanSeeTableRecords([$event])
        ->assertSee('★★★★☆ · Fun, but get there before the crowds.');
});

it('can say how it was on the day itself', function () {
    $today = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-01']);

    Livewire::test(Plans::class)
        ->assertTableActionVisible('went', $today)
        ->assertTableActionVisible('interested', $today)
        ->assertTableActionHidden('missed', $today);
});

it('drops a plan you did not get to', function () {
    $event = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-31']);

    Livewire::test(Plans::class)->callTableAction('missed', $event);

    expect($event->refresh()->status)->toBe(EventStatus::Missed);

    Livewire::test(Plans::class)->assertCanNotSeeTableRecords([$event]);
    Livewire::test(History::class)->assertCanNotSeeTableRecords([$event]);
});

it('drops a whole day of plans you did not get to at once', function () {
    $friday = Event::factory()->for($this->search)->marked(EventStatus::Interested)->count(3)->create(['starts_on' => '2026-10-30']);
    $saturday = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-31']);

    Livewire::test(Plans::class)
        ->callTableBulkAction('missed', $friday)
        ->assertNotified('Marked 3 as Didn\'t go')
        ->assertCanNotSeeTableRecords($friday)
        ->assertCanSeeTableRecords([$saturday]);

    expect($friday->each->refresh()->pluck('status')->unique()->all())->toBe([EventStatus::Missed])
        ->and($saturday->refresh()->status)->toBe(EventStatus::Interested);
});

it('only lets you pick plans that have been and gone to drop at once', function () {
    $passed = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-31']);
    $today = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-01']);
    $soon = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-03']);

    $plans = Livewire::test(Plans::class);

    expect($plans->instance()->getAllSelectableTableRecordKeys())->toBe([(string) $passed->getKey()]);

    $plans->callTableBulkAction('missed', [$passed, $today, $soon]);

    expect($passed->refresh()->status)->toBe(EventStatus::Missed)
        ->and($today->refresh()->status)->toBe(EventStatus::Interested)
        ->and($soon->refresh()->status)->toBe(EventStatus::Interested);
});

it('lists everything you went to, newest first', function () {
    $older = Event::factory()->for($this->search)->marked(EventStatus::Went)->create(['starts_on' => '2026-09-12']);
    $newer = Event::factory()->for(Search::factory()->for($this->user)->create(['kind' => 'discovery']))->marked(EventStatus::Went)->create(['starts_on' => '2026-10-24']);
    $interested = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-10-20']);
    $someoneElses = Event::factory()->marked(EventStatus::Went)->create(['starts_on' => '2026-10-24']);

    Livewire::test(History::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$interested, $someoneElses]);
});

it('narrows events to an area', function () {
    $riverton = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['city' => 'Riverton', 'region' => 'Oregon', 'country' => 'US']);
    $bayport = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['city' => 'Bayport', 'region' => 'Washington', 'country' => 'US']);
    $toronto = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['city' => 'Toronto', 'region' => 'Ontario', 'country' => 'CA']);

    Livewire::test(Plans::class)
        ->filterTable('country', 'US')
        ->assertCanSeeTableRecords([$riverton, $bayport])
        ->assertCanNotSeeTableRecords([$toronto])
        ->filterTable('region', 'Washington')
        ->assertCanSeeTableRecords([$bayport])
        ->assertCanNotSeeTableRecords([$riverton, $toronto]);
});

it('shows when you went to something before, and how it was', function () {
    $visit = Event::factory()->for($this->search)->marked(EventStatus::Went)->create(['starts_on' => '2025-11-02', 'rating' => 5, 'retrospective' => 'Go early.']);
    $again = Event::factory()->for($this->search)->marked(EventStatus::Interested)->create(['starts_on' => '2026-11-08', 'activity_id' => $visit->activity_id]);

    Livewire::test(Plans::class)
        ->assertCanSeeTableRecords([$again])
        ->assertSee('You went on Nov 2, 2025 · ★★★★★ · Go early.');
});
