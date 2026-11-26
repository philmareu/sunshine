<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\Activities\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Activities\RelationManagers\NotesRelationManager;
use App\Filament\Resources\Activities\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Filament\Tables\EventsTable;
use App\Models\Activity;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything Sunshine has found, each with every time it came up and how the visits went.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('Events with this title in this city join this activity when they come up again.'))
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('venue')
                    ->maxLength(255),
                TextInput::make('category')
                    ->maxLength(255),
                TextInput::make('city')
                    ->maxLength(255),
                TextInput::make('region')
                    ->label(__('State or province'))
                    ->maxLength(255),
                TextInput::make('country')
                    ->helperText(__('Two-letter code, e.g. US.'))
                    ->length(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['default' => 2, 'md' => 4])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('avoided_at')
                            ->label(__('Avoided'))
                            ->icon(Heroicon::OutlinedNoSymbol)
                            ->color('danger')
                            ->state(fn (Activity $record): ?string => $record->isAvoided()
                                ? collect([
                                    __('Since :date. Sunshine won\'t suggest it again.', ['date' => $record->avoided_at->timezone(auth()->user()->timezone)->format('M j, Y')]),
                                    $record->avoid_reason,
                                ])->filter()->implode(' ')
                                : null)
                            ->visible(fn (Activity $record): bool => $record->isAvoided())
                            ->columnSpanFull(),
                        TextEntry::make('place')
                            ->label(__('Where'))
                            ->state(fn (Activity $record): ?string => $record->place())
                            ->placeholder('—'),
                        TextEntry::make('category')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('found')
                            ->label(__('Came up'))
                            ->state(fn (Activity $record): string => trans_choice('{1} Once|[2,*] :count times', $record->events()->count())),
                        TextEntry::make('went')
                            ->label(__('You went'))
                            ->state(function (Activity $record): string {
                                $visits = $record->visits()->get();

                                return $visits->isEmpty()
                                    ? __('Not yet')
                                    : collect([
                                        trans_choice('{1} Once|[2,*] :count times', $visits->count()),
                                        EventsTable::stars($visits->avg('rating') ? (int) round($visits->avg('rating')) : null),
                                    ])->filter()->implode(' · ');
                            }),
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->visible(fn (Activity $record): bool => filled($record->description))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    /**
     * Pick from the signed-in user's other activities, by title and place.
     */
    public static function activitySelect(string $name, Activity $except): Select
    {
        return Select::make($name)
            ->searchable()
            ->options(fn (): array => static::getEloquentQuery()
                ->whereKeyNot($except->getKey())
                ->orderBy('title')
                ->get()
                ->mapWithKeys(fn (Activity $activity): array => [$activity->id => collect([$activity->title, $activity->place()])->filter()->implode(' · ')])
                ->all());
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            NotesRelationManager::class,
            PhotosRelationManager::class,
            ChecklistItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }
}
