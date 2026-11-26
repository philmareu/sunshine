<?php

namespace App\Filament\Pages;

use App\Enums\SearchKind;
use App\Models\User;
use BackedEnum;
use Carbon\WeekDay;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Where the user lives and will go, when they can go out and what they do and don't want to see.
 * The scout reads these on every search.
 *
 * @property-read Schema $form
 */
class Preferences extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 6;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->user()->only([
            'home_location', 'places', 'timezone', 'free_all_day', 'free_evenings', 'landing_day', 'interests', 'avoid', 'budget',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Where you are'))
                    ->schema([
                        TextInput::make('home_location')
                            ->label(__('Home location'))
                            ->placeholder(__('e.g. a town, city or neighbourhood'))
                            ->helperText(__('New searches start here. You can search anywhere else when you create one.'))
                            ->maxLength(255),
                        Repeater::make('places')
                            ->label(__('Other places you\'d go'))
                            ->helperText(fn (): string => $this->placesHint())
                            ->schema([
                                TextInput::make('location')
                                    ->label(__('Place'))
                                    ->placeholder(__('e.g. Bayport, WA'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('note')
                                    ->label(__('When you\'d go'))
                                    ->placeholder(__('e.g. only for big concerts'))
                                    ->helperText(__('Leave empty to look for everything you like.'))
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add a place')),
                        Select::make('timezone')
                            ->label(__('Time zone'))
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->helperText(__('Decides what "today" means for you, and when your weekly scan starts.')),
                    ]),
                Section::make(__('When you can go'))
                    ->description(__('Your weekly scan only looks for events on these days. Leave both empty to look at every day.'))
                    ->schema([
                        CheckboxList::make('free_all_day')
                            ->label(__('Free all day'))
                            ->options($this->days())
                            ->columns(7),
                        CheckboxList::make('free_evenings')
                            ->label(__('Free in the evening'))
                            ->options($this->days())
                            ->columns(7)
                            ->helperText(__('From about 5 PM.')),
                        Select::make('landing_day')
                            ->label(__('Landing day'))
                            ->options($this->days())
                            ->required()
                            ->helperText(__('Your weekly scan runs just after midnight on this day, so new events are waiting that morning. "Check now" on Upcoming runs one any time.')),
                    ]),
                Section::make(__('What you like'))
                    ->schema([
                        TagsInput::make('interests')
                            ->label(__('Looking for'))
                            ->placeholder(__('Add an interest'))
                            ->helperText(__('Things you\'d like to find, for whoever goes along: college basketball, live music, crafts for kids, events for seniors. Press Enter after each.')),
                        TagsInput::make('avoid')
                            ->label(__('Not interested in'))
                            ->placeholder(__('Add something to skip'))
                            ->helperText(__('Things you never want suggested.')),
                        TextInput::make('budget')
                            ->label(__('Budget'))
                            ->placeholder(__('e.g. free or under $20 a person'))
                            ->maxLength(255),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save'))
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->user()->update([
            ...$data,
            'places' => array_values($data['places'] ?? []),
        ]);

        Notification::make()->success()->title(__('Saved'))->send();
    }

    /**
     * Each place costs a scan one more run, so say roughly what one costs, going by their scans so far.
     */
    protected function placesHint(): string
    {
        $costPerRun = $this->user()->aiUsages()->whereRelation('search', 'kind', SearchKind::Discovery)->avg('cost');

        if ($costPerRun === null) {
            return __('Every scan searches each place as thoroughly as home, one after another, so each one costs about as much again.');
        }

        return __('Every scan searches each place as thoroughly as home, one after another. Going by your scans so far, each place adds about $:run a scan, or $:month a month with one scan a week.', [
            'run' => number_format((float) $costPerRun, 2),
            'month' => number_format((float) $costPerRun * 52 / 12, 2),
        ]);
    }

    /**
     * The days of the week, Monday first, keyed 0 (Sunday) to 6 as Carbon numbers them.
     *
     * @return array<int, string>
     */
    protected function days(): array
    {
        return collect([...WeekDay::cases()])
            ->sortBy(fn (WeekDay $day): int => ($day->value + 6) % 7)
            ->mapWithKeys(fn (WeekDay $day): array => [$day->value => __($day->name)])
            ->all();
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
