<?php

namespace App\Filament\Resources\Searches\RelationManagers;

use App\Enums\EventStatus;
use App\Filament\Tables\EventsTable;
use App\Models\Search;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * The events a search found, with a tab for each way the family marked them.
 *
 * @property Search $ownerRecord
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Things to do';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('All'))];

        foreach ([EventStatus::Interested, EventStatus::Went, EventStatus::NotForUs] as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->icon($status->getIcon())
                ->badge(fn (): ?int => $this->ownerRecord->events()->where('status', $status)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table, filterByStatus: false)
            ->paginated(false)
            ->poll(fn (): ?string => $this->ownerRecord->status->isInProgress() ? '5s' : null)
            ->emptyState(fn (): ?View => $this->ownerRecord->status->isInProgress() ? view('filament.searches.searching') : null)
            ->emptyStateHeading(__('Nothing found'));
    }
}
