<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Enums\EventStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Activity;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every activity, with a tab for each way the family marked any time it came up, and one for the ones they avoid.
 */
class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected ?string $subheading = 'Everything Sunshine has found. Open one to see every time it came up.';

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('All'))];

        foreach ([EventStatus::Went, EventStatus::Interested, EventStatus::NotForUs] as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->icon($status->getIcon())
                ->badge(fn (): ?int => Activity::query()->whereBelongsTo(auth()->user())->whereHas('events', fn (Builder $query) => $query->where('status', $status))->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('events', fn (Builder $query) => $query->where('status', $status)));
        }

        $tabs['avoided'] = Tab::make(__('Avoided'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->badge(fn (): ?int => Activity::query()->whereBelongsTo(auth()->user())->whereNotNull('avoided_at')->count() ?: null)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('avoided_at'));

        return $tabs;
    }
}
