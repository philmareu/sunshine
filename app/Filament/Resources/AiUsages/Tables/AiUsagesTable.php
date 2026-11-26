<?php

namespace App\Filament\Resources\AiUsages\Tables;

use App\Enums\SearchKind;
use App\Filament\Resources\Searches\SearchResource;
use App\Models\AiUsage;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiUsagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['search', 'source']))
            ->recordUrl(fn (AiUsage $record): ?string => $record->search?->kind === SearchKind::AdHoc ? SearchResource::getUrl('view', ['record' => $record->search]) : null)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('M j, g:i A')
                    ->timezone(fn (): ?string => auth()->user()?->timezone)
                    ->sortable(),
                // Runs recorded before each kept its own location fall back to their search's.
                TextColumn::make('location')
                    ->label(__('Run'))
                    ->state(fn (AiUsage $record): ?string => $record->location ?? $record->search?->location)
                    ->placeholder(__('Deleted search'))
                    ->description(fn (AiUsage $record): ?string => match (true) {
                        $record->purpose === AiUsage::TASTE_PROFILE => __('Taste summary'),
                        $record->source !== null => __('Read :page', ['page' => $record->source->name ?? $record->source->url]),
                        default => $record->search?->kind?->getLabel(),
                    }),
                TextColumn::make('model')
                    ->color('gray'),
                TextColumn::make('input_tokens')
                    ->label(__('Input'))
                    ->numeric()
                    ->description(fn (AiUsage $record): ?string => $record->cache_read_tokens ? __(':count cached', ['count' => number_format($record->cache_read_tokens)]) : null)
                    ->summarize(Sum::make()->label(__('Total'))),
                TextColumn::make('output_tokens')
                    ->label(__('Output'))
                    ->numeric()
                    ->summarize(Sum::make()->label(__('Total'))),
                TextColumn::make('web_searches')
                    ->label(__('Web searches'))
                    ->numeric()
                    ->summarize(Sum::make()->label(__('Total'))),
                TextColumn::make('pages_fetched')
                    ->label(__('Pages fetched'))
                    ->numeric()
                    ->summarize(Sum::make()->label(__('Total'))),
                TextColumn::make('cost')
                    ->money('USD', decimalPlaces: 4)
                    ->placeholder(__('No price'))
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->money('USD', decimalPlaces: 2)),
            ])
            ->emptyStateHeading(__('No AI runs yet'));
    }
}
