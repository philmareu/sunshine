<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Models\Activity;
use App\Models\ActivityNote;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes that hold for every time an activity comes up. They show on its event cards, and the scout reads them.
 *
 * @property Activity $ownerRecord
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Activity $ownerRecord */
        $count = $ownerRecord->notes()->count();

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
                Textarea::make('body')
                    ->hiddenLabel()
                    ->placeholder(__('e.g. Get there before 9, parking fills up'))
                    ->helperText(__('Shows on this activity\'s events every time it comes up, and Sunshine reads it.'))
                    ->required()
                    ->rows(4)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ActivityNote $record): string => str($record->body)->limit(40)->toString())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('body')
                    ->label(__('Note'))
                    ->wrap()
                    ->description(fn (ActivityNote $record): string => $record->created_at->timezone(auth()->user()->timezone)->format('M j, Y')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add a note'))
                    ->modalHeading(__('Add a note')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No notes yet'))
            ->emptyStateDescription(__('Tips for next time, what everyone liked, what to skip.'));
    }
}
