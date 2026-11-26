<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Activity;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Activity $record
 */
class ViewActivity extends ViewRecord
{
    protected static string $resource = ActivityResource::class;

    public function getTitle(): string
    {
        return $this->record->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('avoid')
                ->label(__('Avoid'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->outlined()
                ->visible(fn (): bool => ! $this->record->isAvoided())
                ->modalHeading(__('Never suggest this again?'))
                ->modalDescription(__('Sunshine will leave it out of every scan and search from now on, and steer away from things like it.'))
                ->modalSubmitActionLabel(__('Avoid'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('Why?'))
                        ->helperText(__('Optional, but it helps Sunshine skip things like it too.'))
                        ->rows(3)
                        ->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->record->avoid($data['reason'])),
            Action::make('stop_avoiding')
                ->label(__('Stop avoiding'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (): bool => $this->record->isAvoided())
                ->requiresConfirmation()
                ->modalDescription(__('Sunshine can suggest it again.'))
                ->action(fn () => $this->record->stopAvoiding()),
            Action::make('merge')
                ->label(__('Merge'))
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->color('gray')
                ->outlined()
                ->modalHeading(fn (): string => __('Merge into :title', ['title' => $this->record->title]))
                ->modalDescription(__('Pick activities that are really this one. Their dates, notes, photos and checklist move here, and they are removed.'))
                ->modalSubmitActionLabel(__('Merge'))
                ->schema([
                    ActivityResource::activitySelect('others', $this->record)
                        ->label(__('Same thing as'))
                        ->multiple()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->absorb(ActivityResource::getEloquentQuery()->whereKey($data['others'])->get());

                    Notification::make()->success()->title(__('Merged'))->send();

                    $this->redirect(ActivityResource::getUrl('view', ['record' => $this->record]));
                }),
            EditAction::make(),
        ];
    }
}
