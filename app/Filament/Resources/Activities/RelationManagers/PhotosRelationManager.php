<?php

namespace App\Filament\Resources\Activities\RelationManagers;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Photos from an activity, newest visit first. Each can be tied to the visit it was taken on, and the first
 * photo from a visit shows on its event card.
 *
 * @property Activity $ownerRecord
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Activity $ownerRecord */
        $count = $ownerRecord->photos()->count();

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
                TextInput::make('caption')
                    ->maxLength(255),
                $this->visitSelect(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ActivityPhoto $record): string => $record->caption ?? __('Photo'))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('event')
                ->orderByDesc(Event::query()->select('starts_on')->whereColumn('events.id', 'activity_photos.event_id'))
                ->latest('id'))
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (ActivityPhoto $record): string => $record->url(), shouldOpenInNewTab: true)
            ->columns([
                Stack::make([
                    ImageColumn::make('path')
                        ->disk(ActivityPhoto::disk())
                        ->visibility('private')
                        ->imageHeight(220)
                        ->imageWidth('100%')
                        ->extraImgAttributes(['style' => 'object-fit: cover; border-radius: 0.5rem;', 'loading' => 'lazy']),
                    TextColumn::make('caption'),
                    TextColumn::make('event.starts_on')
                        ->date('M j, Y')
                        ->color('gray')
                        ->size(TextSize::Small),
                ])->space(2),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Add photos'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->modalHeading(__('Add photos'))
                    ->modalSubmitActionLabel(__('Add'))
                    ->schema([
                        FileUpload::make('photos')
                            ->hiddenLabel()
                            ->image()
                            ->multiple()
                            ->required()
                            ->disk(ActivityPhoto::disk())
                            ->directory('activity-photos')
                            ->visibility('private')
                            ->maxSize(20 * 1024)
                            ->automaticallyResizeImagesMode('contain')
                            ->automaticallyResizeImagesToWidth('2400')
                            ->automaticallyResizeImagesToHeight('2400')
                            ->automaticallyUpscaleImagesWhenResizing(false),
                        $this->visitSelect(),
                    ])
                    ->action(function (array $data): void {
                        foreach ($data['photos'] as $path) {
                            $this->ownerRecord->photos()->create(['path' => $path, 'event_id' => $data['event_id']]);
                        }

                        Notification::make()->success()->title(trans_choice('{1} Photo added|[2,*] :count photos added', count($data['photos'])))->send();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No photos yet'))
            ->emptyStateDescription(__('Add some from a visit to remember it by.'));
    }

    /**
     * Which time it came up the photos are from, newest first. New photos default to the last visit.
     */
    protected function visitSelect(): Select
    {
        return Select::make('event_id')
            ->label(__('From'))
            ->placeholder(__('No particular visit'))
            ->default(fn (): ?int => $this->ownerRecord->visits()->latest('starts_on')->value('id'))
            ->options(fn (): array => $this->ownerRecord->events()
                ->latest('starts_on')
                ->get()
                ->mapWithKeys(fn (Event $event): array => [$event->id => $event->starts_on->format('M j, Y').($event->status ? ' · '.$event->status->getLabel() : '')])
                ->all());
    }
}
