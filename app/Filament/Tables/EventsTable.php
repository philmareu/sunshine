<?php

namespace App\Filament\Tables;

use App\Enums\EventStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\ActivityPhoto;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Events as cards. Before an event the family marks it interested or not for us, or says they can't make it;
 * afterwards, whether they went and how it was. The scout learns from all of it but "can't make it". Used by Upcoming, Plans, History, each search's results and
 * each activity's page, where $showActivity is off because every card is that activity.
 */
class EventsTable
{
    public static function configure(Table $table, bool $filterByStatus = true, bool $showActivity = true): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['activity.visits', 'activity.notes', 'activity.checklistItems', 'photos']))
            ->defaultSort('starts_on')
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (Event $record): string => $record->url, shouldOpenInNewTab: true)
            ->columns([
                Stack::make([
                    ImageColumn::make('photo')
                        ->state(fn (Event $record): ?string => $record->photos->sortBy('id')->first()?->path)
                        ->disk(ActivityPhoto::disk())
                        ->visibility('private')
                        ->imageHeight(160)
                        ->imageWidth('100%')
                        ->extraImgAttributes(['style' => 'object-fit: cover; border-radius: 0.5rem;', 'loading' => 'lazy']),
                    Split::make([
                        TextColumn::make('starts_on')
                            ->date('D, M j')
                            ->description(fn (Event $record): ?string => $record->time)
                            ->sortable()
                            ->grow(false),
                        TextColumn::make('category')
                            ->badge()
                            ->color('gray')
                            ->alignEnd(),
                    ]),
                    TextColumn::make('title')
                        ->weight(FontWeight::Bold)
                        ->searchable(),
                    TextColumn::make('venue')
                        ->state(fn (Event $record): ?string => collect([$record->venue, collect([$record->city, $record->region])->filter()->implode(', '), $record->cost])->filter()->implode(' · ') ?: null)
                        ->color('gray')
                        ->size(TextSize::Small),
                    TextColumn::make('description')
                        ->size(TextSize::Small)
                        ->lineClamp(3),
                    TextColumn::make('reason')
                        ->size(TextSize::Small)
                        ->color('primary')
                        ->icon(fn (Event $record): Heroicon => $record->is_wildcard ? Heroicon::OutlinedSparkles : Heroicon::OutlinedLightBulb)
                        ->tooltip(fn (Event $record): ?string => $record->is_wildcard ? __('Wildcard: outside your usual interests') : null),
                    TextColumn::make('previous_visit')
                        ->state(fn (Event $record): ?string => ($visit = $record->previousVisit())
                            ? collect([
                                __('You went on :date', ['date' => $visit->starts_on->format('M j, Y')]),
                                static::stars($visit->rating),
                                $visit->retrospective,
                            ])->filter()->implode(' · ')
                            : null)
                        ->size(TextSize::Small)
                        ->color('success')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible($showActivity),
                    TextColumn::make('activity_note')
                        ->state(fn (Event $record): ?string => $record->activity->notes->sortByDesc('id')->first()?->body)
                        ->description(fn (Event $record): ?string => $record->activity->notes->count() > 1
                            ? trans_choice('{1} One more note|[2,*] :count more notes', $record->activity->notes->count() - 1)
                            : null)
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->lineClamp(3)
                        ->visible($showActivity),
                    TextColumn::make('hidden_at')
                        ->state(fn (Event $record): ?string => $record->hidden_at ? __('Can\'t make it') : null)
                        ->badge()
                        ->color('gray')
                        ->icon(Heroicon::OutlinedXMark),
                    TextColumn::make('status')
                        ->badge()
                        ->description(fn (Event $record): ?string => match ($record->status) {
                            EventStatus::Went => collect([static::stars($record->rating), $record->retrospective])->filter()->implode(' · ') ?: null,
                            EventStatus::NotForUs => $record->note,
                            default => null,
                        }),
                ])->space(2),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(EventStatus::class)
                    ->visible($filterByStatus),
                static::areaFilter('country', __('Country')),
                static::areaFilter('region', __('State or province')),
                static::areaFilter('city', __('City')),
                TernaryFilter::make('is_wildcard')
                    ->label(__('Wildcards')),
            ])
            ->recordActions([
                static::interestedAction(),
                static::checklistAction(),
                static::cantMakeItAction(),
                static::interestedButCantMakeItAction(),
                static::notForUsAction(),
                static::wentAction(),
                static::missedAction(),
                static::activityAction()
                    ->visible($showActivity),
            ]);
    }

    /**
     * Narrow the cards to one place, choosing from the places the signed-in user's events are in.
     */
    protected static function areaFilter(string $column, string $label): SelectFilter
    {
        return SelectFilter::make($column)
            ->label($label)
            ->searchable()
            ->options(fn (): array => Event::query()
                ->whereHas('search', fn (Builder $query) => $query->whereBelongsTo(auth()->user()))
                ->whereNotNull($column)
                ->distinct()
                ->orderBy($column)
                ->pluck($column, $column)
                ->all());
    }

    /**
     * One click to mark it, another to take it back.
     */
    protected static function interestedAction(): Action
    {
        return Action::make('interested')
            ->label(EventStatus::Interested->getLabel())
            ->icon(EventStatus::Interested->getIcon())
            ->color(fn (Event $record): string => $record->status === EventStatus::Interested ? 'primary' : 'gray')
            ->visible(fn (Event $record): bool => ! static::isPast($record))
            ->action(function (Event $record): void {
                if ($record->status === EventStatus::Interested) {
                    $record->update(['status' => null, 'marked_at' => now()]);

                    return;
                }

                // Planning to go means they can make it after all.
                $record->update(['status' => EventStatus::Interested, 'marked_at' => now(), 'hidden_at' => null]);

                // Upcoming hides it straight away, so say where it went.
                Notification::make()->success()->title(__('Added to Plans'))->send();
            });
    }

    /**
     * For a date they can't make, say because they're busy then: one click takes it off Upcoming and Plans, another
     * puts it back. It leaves the event's mark alone, so the scout learns nothing from it.
     */
    protected static function cantMakeItAction(): Action
    {
        return Action::make('cant_make_it')
            ->label(fn (Event $record): string => $record->hidden_at ? __('Show it again') : __('Can\'t make it'))
            ->icon(fn (Event $record): Heroicon => $record->hidden_at ? Heroicon::OutlinedEye : Heroicon::OutlinedXMark)
            ->iconButton()
            ->tooltip(fn (Event $record): string => $record->hidden_at ? __('Show it again') : __('Can\'t make it'))
            ->color('gray')
            ->visible(fn (Event $record): bool => ! static::isPast($record))
            ->action(fn (Event $record) => $record->update(['hidden_at' => $record->hidden_at ? null : now()]));
    }

    /**
     * Both at once, for something they'd like but can't make that date: the scout learns they liked it, and it goes
     * off Upcoming without landing in Plans. Once it is marked Interested, "Can't make it" alone does the same.
     */
    protected static function interestedButCantMakeItAction(): Action
    {
        return Action::make('interested_cant_make_it')
            ->label(__('Interested, but can\'t make it'))
            ->icon(Heroicon::OutlinedFaceFrown)
            ->iconButton()
            ->tooltip(__('Interested, but can\'t make it'))
            ->color('gray')
            ->visible(fn (Event $record): bool => ! static::isPast($record) && $record->status !== EventStatus::Interested)
            ->action(function (Event $record): void {
                $record->update(['status' => EventStatus::Interested, 'marked_at' => now(), 'hidden_at' => now()]);

                Notification::make()->success()->title(__('Noted: you\'d like it, but can\'t make it'))->send();
            });
    }

    /**
     * Tick off the activity's checklist for this visit. Each visit starts with nothing ticked.
     */
    protected static function checklistAction(): Action
    {
        return Action::make('checklist')
            ->label(__('Checklist'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->iconButton()
            ->tooltip(__('Checklist'))
            ->badge(fn (Event $record): string => $record->checkedCount().'/'.$record->activity->checklistItems->count())
            ->badgeColor(fn (Event $record): string => $record->checkedCount() === $record->activity->checklistItems->count() ? 'success' : 'gray')
            ->color('gray')
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Interested && $record->activity->checklistItems->isNotEmpty())
            ->modalHeading(fn (Event $record): string => $record->title)
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (Event $record): array => ['checked_items' => $record->checked_items ?? []])
            ->schema([
                CheckboxList::make('checked_items')
                    ->hiddenLabel()
                    ->options(fn (Event $record): array => $record->activity->checklistItems->pluck('label', 'id')->all())
                    ->bulkToggleable(),
            ])
            ->action(fn (Event $record, array $data) => $record->update([
                'checked_items' => array_map(intval(...), $data['checked_items']),
            ]));
    }

    /**
     * With a note on what didn't work for them. It can mark the same thing's other dates to come, and ones like it,
     * at the same time, and avoid the activity for good.
     */
    protected static function notForUsAction(): Action
    {
        return Action::make('not_for_us')
            ->label(EventStatus::NotForUs->getLabel())
            ->icon(EventStatus::NotForUs->getIcon())
            ->iconButton()
            ->tooltip(EventStatus::NotForUs->getLabel())
            ->color(fn (Event $record): string => $record->status === EventStatus::NotForUs ? 'danger' : 'gray')
            ->visible(fn (Event $record): bool => ! static::isPast($record))
            ->modalHeading(fn (Event $record): string => $record->title)
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (Event $record): array => [
                'note' => $record->status === EventStatus::NotForUs ? $record->note : null,
                // Once it is marked, the ones still unmarked were left that way on purpose.
                'related' => $record->status === EventStatus::NotForUs ? [] : $record->relatedToCome()->modelKeys(),
                'avoid' => $record->activity->isAvoided(),
            ])
            ->schema([
                Textarea::make('note')
                    ->label(__('What didn\'t work for you?'))
                    ->helperText(__('Optional, but the more you say, the better future searches get.'))
                    ->rows(3)
                    ->maxLength(1000),
                CheckboxList::make('related')
                    ->label(__('Mark these too'))
                    ->helperText(__('Its other dates coming up, and ones with the same name but for a number, like another level of a class. Each gets your note.'))
                    ->options(fn (Event $record): array => $record->relatedToCome()
                        ->mapWithKeys(fn (Event $event): array => [$event->id => $event->title.' · '.$event->starts_on->format('D, M j')])
                        ->all())
                    ->visible(fn (Event $record): bool => $record->relatedToCome()->isNotEmpty()),
                Toggle::make('avoid')
                    ->label(__('Never suggest this again'))
                    ->helperText(__('Not just this date: Sunshine leaves it out every time it comes up.')),
            ])
            ->action(function (Event $record, array $data): void {
                $mark = ['status' => EventStatus::NotForUs, 'note' => $data['note'], 'marked_at' => now()];

                $record->update($mark);
                $record->relatedToCome()->whereIn('id', $data['related'] ?? [])->each->update($mark);

                match (true) {
                    $data['avoid'] && ! $record->activity->isAvoided() => $record->activity->avoid($data['note']),
                    ! $data['avoid'] && $record->activity->isAvoided() => $record->activity->stopAvoiding(),
                    default => null,
                };
            });
    }

    /**
     * From the day of the event on: they went, and how it was.
     */
    protected static function wentAction(): Action
    {
        return Action::make('went')
            ->label(fn (Event $record): string => $record->status === EventStatus::Went ? __('Went') : __('We went'))
            ->icon(EventStatus::Went->getIcon())
            ->color(fn (Event $record): string => $record->status === EventStatus::Went ? 'success' : 'gray')
            ->visible(fn (Event $record): bool => static::hasArrived($record))
            ->modalHeading(fn (Event $record): string => $record->title)
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (Event $record): array => $record->only(['rating', 'retrospective']))
            ->schema([
                ToggleButtons::make('rating')
                    ->label(__('How was it?'))
                    ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                    ->helperText(__('1 is not great, 5 is loved it.'))
                    ->inline(),
                Textarea::make('retrospective')
                    ->label(__('Anything to remember for next time?'))
                    ->helperText(__('What you enjoyed, what you didn\'t, tips like "get there early". Sunshine reads this when something like it comes up again.'))
                    ->rows(4)
                    ->maxLength(2000),
            ])
            ->action(fn (Event $record, array $data) => $record->update([
                'status' => EventStatus::Went,
                'rating' => $data['rating'],
                'retrospective' => $data['retrospective'],
                'marked_at' => now(),
            ]));
    }

    /**
     * For something they were interested in that has been and gone.
     */
    protected static function missedAction(): Action
    {
        return Action::make('missed')
            ->label(EventStatus::Missed->getLabel())
            ->icon(EventStatus::Missed->getIcon())
            ->color('gray')
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Interested && static::isPast($record))
            ->action(fn (Event $record) => $record->update([
                'status' => EventStatus::Missed,
                'marked_at' => now(),
            ]));
    }

    /**
     * The same for every selected event at once, such as a whole day of plans. Only what they were interested in
     * that has been and gone is marked; anything else selected is left as it is.
     */
    public static function missedBulkAction(): BulkAction
    {
        return BulkAction::make('missed')
            ->label(EventStatus::Missed->getLabel())
            ->icon(EventStatus::Missed->getIcon())
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (Collection $records): string => trans_choice('{1} Didn\'t go to this one?|[2,*] Didn\'t go to these :count?', $records->count()))
            ->modalDescription(__('They come off Plans, and Sunshine won\'t learn anything about your taste from them.'))
            ->modalSubmitActionLabel(EventStatus::Missed->getLabel())
            ->action(function (Collection $records): void {
                $missed = $records->filter(fn (Event $record): bool => $record->status === EventStatus::Interested && static::isPast($record));

                $missed->each->update(['status' => EventStatus::Missed, 'marked_at' => now()]);

                Notification::make()
                    ->success()
                    ->title(trans_choice('{0} Nothing to mark|{1} Marked one as Didn\'t go|[2,*] Marked :count as Didn\'t go', $missed->count()))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Every time this came up, and how each visit went.
     */
    protected static function activityAction(): Action
    {
        return Action::make('activity')
            ->label(__('Activity'))
            ->icon(Heroicon::OutlinedRectangleStack)
            ->iconButton()
            ->tooltip(__('Every time this came up'))
            ->color('gray')
            ->url(fn (Event $record): string => ActivityResource::getUrl('view', ['record' => $record->activity_id]));
    }

    /**
     * Whether the event's date is behind the signed-in user, where they live.
     */
    protected static function isPast(Event $record): bool
    {
        return $record->isBefore(auth()->user()->today());
    }

    /**
     * Whether the event's day has come, today included.
     */
    protected static function hasArrived(Event $record): bool
    {
        return $record->starts_on->toDateString() <= auth()->user()->today()->toDateString();
    }

    public static function stars(?int $rating): ?string
    {
        return $rating ? str_repeat('★', $rating).str_repeat('☆', 5 - $rating) : null;
    }
}
