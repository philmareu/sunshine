<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSources extends ManageRecords
{
    protected static string $resource = SourceResource::class;

    protected ?string $subheading = 'Pages every scan checks, on top of searching the web, each in the search for its place.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add source'))
                ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => auth()->id()]),
        ];
    }
}
