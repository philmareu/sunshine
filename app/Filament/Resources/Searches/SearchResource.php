<?php

namespace App\Filament\Resources\Searches;

use App\Enums\SearchKind;
use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Filament\Resources\Searches\Pages\ViewSearch;
use App\Filament\Resources\Searches\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Searches\RelationManagers\SourcesRelationManager;
use App\Filament\Resources\Searches\Schemas\SearchForm;
use App\Filament\Resources\Searches\Tables\SearchesTable;
use App\Models\Search;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SearchResource extends Resource
{
    protected static ?string $model = Search::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'location';

    /**
     * @return Builder<Search>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereBelongsTo(auth()->user())
            ->where('kind', SearchKind::AdHoc);
    }

    public static function form(Schema $schema): Schema
    {
        return SearchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['default' => 2, 'md' => 5])
                    ->columnSpanFull()
                    ->poll(fn (Search $record): ?string => $record->status->isInProgress() ? '5s' : null)
                    ->schema([
                        TextEntry::make('location'),
                        TextEntry::make('starts_on')
                            ->label(__('Dates'))
                            ->formatStateUsing(fn (Search $record): string => $record->starts_on->toFormattedDayDateString().' – '.$record->ends_on->toFormattedDayDateString()),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('events_count')
                            ->label(__('Found'))
                            ->state(fn (Search $record): int => $record->events()->count()),
                        TextEntry::make('cost')
                            ->label(__('Cost'))
                            ->state(fn (Search $record): ?float => $record->usages()->sum('cost') ?: null)
                            ->money('USD', decimalPlaces: 4)
                            ->placeholder('—'),
                        TextEntry::make('direction')
                            ->label(__('Direction'))
                            ->visible(fn (Search $record): bool => filled($record->direction))
                            ->columnSpanFull(),
                        TextEntry::make('error')
                            ->color('danger')
                            ->visible(fn (Search $record): bool => filled($record->error))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SearchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            SourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSearches::route('/'),
            'create' => CreateSearch::route('/create'),
            'view' => ViewSearch::route('/{record}'),
        ];
    }
}
