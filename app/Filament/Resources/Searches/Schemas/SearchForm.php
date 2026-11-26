<?php

namespace App\Filament\Resources\Searches\Schemas;

use App\Ai\Agents\EventScout;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SearchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('location')
                    ->label(__('Where'))
                    ->default(fn (): ?string => auth()->user()?->home_location)
                    ->helperText(__('Defaults to your home location. Change it to explore somewhere you\'re visiting.'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                DatePicker::make('starts_on')
                    ->label(__('From'))
                    ->default(fn (): ?string => auth()->user()?->today()->toDateString())
                    ->required(),
                DatePicker::make('ends_on')
                    ->label(__('To'))
                    ->default(fn (): ?string => auth()->user()?->today()->addWeek()->toDateString())
                    ->required()
                    ->afterOrEqual('starts_on')
                    ->maxDate(fn (Get $get): ?string => filled($get('starts_on')) ? CarbonImmutable::parse($get('starts_on'))->addMonths(3)->toDateString() : null),
                Textarea::make('direction')
                    ->label(__('Anything specific?'))
                    ->placeholder(__('e.g. Rainy day, something indoors for the kids. Or: date night, no sports.'))
                    ->helperText(__('Optional. The search already uses your preferences and what you\'ve liked before.'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                Repeater::make('sources')
                    ->label(__('Pages to check'))
                    ->helperText(__('Optional. Calendars or event pages for this search only, such as a festival\'s schedule; at most :pages.', ['pages' => EventScout::MAX_PAGES_FETCHED]))
                    ->relationship()
                    ->schema([
                        TextInput::make('url')
                            ->label(__('Address'))
                            ->type('url')
                            ->rules([fn (): Closure => Source::addressRule()])
                            ->required()
                            ->maxLength(2048),
                        TextInput::make('name')
                            ->placeholder(__('e.g. Festival schedule'))
                            ->maxLength(255),
                        Toggle::make('needs_browser')
                            ->label(__('Open it in a browser'))
                            ->helperText(__('For a page that fills in its events with JavaScript.'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->maxItems(EventScout::MAX_PAGES_FETCHED)
                    ->addActionLabel(__('Add a page'))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'user_id' => auth()->id()])
                    ->columnSpanFull(),
            ]);
    }
}
