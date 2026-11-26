<?php

namespace App\Filament\Resources\Searches\Tables;

use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Models\Search;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SearchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('events'))
            ->poll(fn (): ?string => Search::query()->whereBelongsTo(auth()->user())->where('kind', SearchKind::AdHoc)->whereIn('status', [SearchStatus::Pending, SearchStatus::Running])->exists() ? '5s' : null)
            ->columns([
                TextColumn::make('location')
                    ->description(fn (Search $record): ?string => str($record->direction)->limit(80)->toString() ?: null)
                    ->searchable(),
                TextColumn::make('starts_on')
                    ->label(__('Dates'))
                    ->formatStateUsing(fn (Search $record): string => $record->starts_on->format('M j').' – '.$record->ends_on->format('M j, Y')),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('events_count')
                    ->label(__('Found'))
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label(__('Searched'))
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->disabled(fn (Search $record): bool => $record->hasMarkedEvents())
                    ->tooltip(fn (Search $record): ?string => $record->hasMarkedEvents() ? __('Kept because you marked some of its events.') : null),
            ])
            ->emptyStateHeading(__('No searches yet'))
            ->emptyStateDescription(__('Start one to find things to do near home, or anywhere you\'re headed.'));
    }
}
