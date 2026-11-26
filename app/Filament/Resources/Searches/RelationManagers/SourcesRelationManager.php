<?php

namespace App\Filament\Resources\Searches\RelationManagers;

use App\Filament\Resources\Sources\SourceResource;
use App\Models\Search;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The pages a search was asked to check, with what the scout made of each. Only shown when it has some.
 *
 * @property Search $ownerRecord
 */
class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    protected static ?string $title = 'Pages checked';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Search && $ownerRecord->sources()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->defaultSort('id')
            ->poll(fn (): ?string => $this->ownerRecord->status->isInProgress() ? '5s' : null)
            ->columns([
                SourceResource::nameColumn(),
                SourceResource::lastCheckColumn(),
            ]);
    }
}
