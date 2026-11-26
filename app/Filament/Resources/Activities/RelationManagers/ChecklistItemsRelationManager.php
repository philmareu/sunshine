<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Models\Activity;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The checklist for every time an activity comes up. Each event the family is interested in ticks off its own copy.
 *
 * @property Activity $ownerRecord
 */
class ChecklistItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistItems';

    protected static ?string $title = 'Checklist';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Activity $ownerRecord */
        $count = $ownerRecord->checklistItems()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->hiddenLabel()
                    ->placeholder(__('e.g. Bring cash'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label(__('Item')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add an item'))
                    ->modalHeading(__('Add to the checklist'))
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'sort' => ($this->ownerRecord->checklistItems()->max('sort') ?? 0) + 1]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No checklist yet'))
            ->emptyStateDescription(__('Things to bring or do before you go. Tick them off on each event you\'re interested in.'));
    }
}
