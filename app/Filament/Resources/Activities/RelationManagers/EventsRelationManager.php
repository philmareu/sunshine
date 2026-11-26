<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Tables\EventsTable;
use App\Models\Activity;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every time an activity came up, newest first, with how each one went. A date taken for the wrong thing can be moved.
 *
 * @property Activity $ownerRecord
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Every time';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table, showActivity: false)
            ->defaultSort('starts_on', 'desc')
            ->pushRecordActions([
                $this->moveAction(),
            ]);
    }

    /**
     * For a date that was taken for the wrong thing: move it to the activity it really is, or to a new one.
     */
    protected function moveAction(): Action
    {
        return Action::make('move')
            ->label(__('Move'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->iconButton()
            ->tooltip(__('Not this? Move it'))
            ->color('gray')
            ->modalHeading(fn (Event $record): string => __('Move :date', ['date' => $record->starts_on->format('M j, Y')]))
            ->modalDescription(__('Move this date to the activity it really is, or to a new one of its own. Its photos go with it.'))
            ->modalSubmitActionLabel(__('Move'))
            ->fillForm(fn (Event $record): array => ['title' => $record->title])
            ->schema([
                ActivityResource::activitySelect('activity_id', $this->ownerRecord)
                    ->label(__('Move to'))
                    ->placeholder(__('A new activity'))
                    ->live(),
                TextInput::make('title')
                    ->label(__('New activity\'s title'))
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => blank($get('activity_id'))),
            ])
            ->action(function (Event $record, array $data): void {
                $record->moveTo(
                    filled($data['activity_id']) ? ActivityResource::getEloquentQuery()->findOrFail($data['activity_id']) : null,
                    $data['title'] ?? null,
                );

                Notification::make()->success()->title(__('Moved'))->send();

                // Reload so the counts on the page add up, or follow the date if nothing is left here.
                $stillHere = Activity::query()->whereKey($this->ownerRecord->getKey())->exists();

                $this->redirect(ActivityResource::getUrl('view', ['record' => $stillHere ? $this->ownerRecord : $record->activity_id]));
            });
    }
}
