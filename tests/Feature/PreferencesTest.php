<?php

use App\Enums\SearchKind;
use App\Filament\Pages\Preferences;
use App\Models\AiUsage;
use App\Models\Search;
use App\Models\User;
use Carbon\CarbonInterface;
use Livewire\Livewire;

it('saves where they are, when they can go and what to look for and avoid', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->fillForm([
            'home_location' => 'Springfield',
            'timezone' => 'America/Chicago',
            'free_all_day' => [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY],
            'free_evenings' => [CarbonInterface::FRIDAY],
            'landing_day' => CarbonInterface::WEDNESDAY,
            'interests' => ['live music', 'college basketball'],
            'avoid' => ['comedy'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())
        ->home_location->toBe('Springfield')
        ->timezone->toBe('America/Chicago')
        ->free_all_day->toEqual([CarbonInterface::SATURDAY, CarbonInterface::SUNDAY])
        ->free_evenings->toEqual([CarbonInterface::FRIDAY])
        ->landing_day->toBe(CarbonInterface::WEDNESDAY)
        ->interests->toBe(['live music', 'college basketball'])
        ->avoid->toBe(['comedy']);
});

it('only accepts a real time zone', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Preferences::class)
        ->fillForm(['timezone' => 'Mars/Olympus_Mons'])
        ->call('save')
        ->assertHasFormErrors(['timezone']);
});

it('saves other places and a budget', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->fillForm([
            'places' => [['location' => 'Bayport, WA', 'note' => 'Only for big concerts']],
            'budget' => 'Under $20 a person',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())
        ->places->toBe([['location' => 'Bayport, WA', 'note' => 'Only for big concerts']])
        ->budget->toBe('Under $20 a person');
});

it('says roughly what each other place adds to a scan', function () {
    $user = User::factory()->create();
    $usage = fn (Search $search, float $cost) => AiUsage::query()->create([
        'user_id' => $user->id, 'search_id' => $search->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5',
        'input_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'output_tokens' => 0, 'web_searches' => 0,
        'cost' => $cost,
    ]);

    $this->actingAs($user);

    Livewire::test(Preferences::class)->assertSee('so each one costs about as much again');

    $usage(Search::factory()->for($user)->create(['kind' => SearchKind::Discovery]), 0.5);
    $usage(Search::factory()->for($user)->create(['kind' => SearchKind::Discovery]), 0.7);
    $usage(Search::factory()->for($user)->create(), 5);

    Livewire::test(Preferences::class)->assertSee('each place adds about $0.60 a scan, or $2.60 a month');
});
