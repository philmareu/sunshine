<?php

namespace App\Filament\Resources\Searches\Pages;

use App\Enums\SearchStatus;
use App\Filament\Resources\Searches\SearchResource;
use App\Jobs\RunSearch;
use App\Models\Search;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Search $record
 */
class ViewSearch extends ViewRecord
{
    protected static string $resource = SearchResource::class;

    public function getTitle(): string
    {
        return $this->record->location;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label(__('Try again'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->record->status === SearchStatus::Failed)
                ->action(function (): void {
                    $this->record->update(['status' => SearchStatus::Pending, 'error' => null]);

                    RunSearch::dispatch($this->record->getKey());

                    // Reload so the events table picks up the new status and starts polling.
                    $this->redirect(SearchResource::getUrl('view', ['record' => $this->record]));
                }),
            DeleteAction::make()
                ->disabled(fn (): bool => $this->record->hasMarkedEvents())
                ->tooltip(fn (): ?string => $this->record->hasMarkedEvents() ? __('Kept because you marked some of its events.') : null),
        ];
    }
}
