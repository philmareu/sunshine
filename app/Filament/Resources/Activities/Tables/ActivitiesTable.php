<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Filament\Tables\EventsTable;
use App\Models\Activity;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('events_max_starts_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['events', 'visits'])
                ->withMax('events', 'starts_on')
                ->withAvg('visits', 'rating'))
            ->columns([
                TextColumn::make('title')
                    ->weight(FontWeight::Bold)
                    ->description(fn (Activity $record): ?string => $record->place())
                    ->icon(fn (Activity $record): ?Heroicon => $record->isAvoided() ? Heroicon::OutlinedNoSymbol : null)
                    ->iconColor('danger')
                    ->tooltip(fn (Activity $record): ?string => $record->isAvoided() ? __('Avoided') : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('events_count')
                    ->label(__('Came up'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('visits_count')
                    ->label(__('Went'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('visits_avg_rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (?string $state): ?string => EventsTable::stars($state ? (int) round((float) $state) : null))
                    ->sortable(),
                TextColumn::make('events_max_starts_on')
                    ->label(__('Latest'))
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->searchable()
                    ->options(fn (): array => static::options('category')),
                static::areaFilter('country', __('Country')),
                static::areaFilter('region', __('State or province')),
                static::areaFilter('city', __('City')),
            ])
            ->emptyStateHeading(__('Nothing here yet'))
            ->emptyStateDescription(__('Everything Sunshine finds lands here, with every time it comes up.'));
    }

    protected static function areaFilter(string $column, string $label): SelectFilter
    {
        return SelectFilter::make($column)
            ->label($label)
            ->searchable()
            ->options(fn (): array => static::options($column));
    }

    /**
     * The values a column holds across the signed-in user's activities.
     *
     * @return array<string, string>
     */
    protected static function options(string $column): array
    {
        return Activity::query()
            ->whereBelongsTo(auth()->user())
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
