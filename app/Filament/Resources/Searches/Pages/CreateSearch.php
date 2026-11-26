<?php

namespace App\Filament\Resources\Searches\Pages;

use App\Filament\Resources\Searches\SearchResource;
use App\Jobs\RunSearch;
use Filament\Resources\Pages\CreateRecord;

class CreateSearch extends CreateRecord
{
    protected static string $resource = SearchResource::class;

    protected static ?string $title = 'New search';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'user_id' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        RunSearch::dispatch($this->record->getKey());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Searching… results appear here in a minute or two.');
    }
}
