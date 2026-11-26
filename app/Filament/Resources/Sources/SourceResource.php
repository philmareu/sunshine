<?php

namespace App\Filament\Resources\Sources;

use App\Ai\Agents\EventScout;
use App\Filament\Resources\Sources\Pages\ManageSources;
use App\Models\Source;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Pages every scan checks, on top of searching the web, each in the run for its place. Pages for one ad hoc search
 * are added with that search instead.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'url';

    /**
     * @return Builder<Source>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user())->whereNull('search_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('url')
                    ->label(__('Address'))
                    ->type('url')
                    ->rules([fn (): Closure => Source::addressRule()])
                    ->required()
                    ->maxLength(2048)
                    ->helperText(__('An events calendar, a feed, or a single event\'s page. For a calendar with a page for each day, put {date} where the date goes, or {date:Y/m/d} to give its form, e.g. https://cityguide.example.com/events/{date:Y/m/d}: each scan reads it for each day you\'re free.')),
                TextInput::make('name')
                    ->placeholder(__('e.g. University athletics'))
                    ->maxLength(255),
                Toggle::make('needs_browser')
                    ->label(__('Open it in a browser'))
                    ->helperText(__('For a page that fills in its events with JavaScript, so they aren\'t in the page Sunshine gets. It takes a few seconds longer.')),
                Select::make('place')
                    ->label(__('Checked in'))
                    ->options(fn (): array => collect(static::user()->otherPlaces())->pluck('location', 'location')->all())
                    ->placeholder(fn (): string => __('Near home (:home)', ['home' => static::user()->home_location ?? __('not set')]))
                    ->helperText(__('Each scan reads it in the search for this place. Every watched page there leaves that search one fewer web search, down to :searches, so a few good pages work better than many.', ['searches' => EventScout::MIN_WEB_SEARCHES])),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->defaultSort('created_at')
            ->columns([
                static::nameColumn()
                    ->searchable(['name', 'url']),
                static::lastCheckColumn(),
                TextColumn::make('place')
                    ->label(__('Checked in'))
                    ->state(fn (Source $record): string => $record->runsIn(array_column(static::user()->otherPlaces(), 'location')) ?? __('Near home')),
                TextColumn::make('created_at')
                    ->label(__('Added'))
                    ->since(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('Nothing watched yet'))
            ->emptyStateDescription(__('Add a calendar, feed or event page and every weekly scan will check it.'));
    }

    /**
     * A page's name, or its address when it has none, and whether Sunshine opens it in a browser. The address itself
     * is on its edit form, so a long one doesn't crowd out the last check.
     */
    public static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->state(fn (Source $record): string => $record->name ?? Str::of($record->url)->replaceMatches('#^https?://(www\.)?#', '')->limit(60)->toString())
            ->wrap()
            ->width('25%')
            ->description(fn (Source $record): ?string => $record->needs_browser ? __('Opened in a browser') : null);
    }

    /**
     * What the last run made of a page: the scout's note, when it checked, and whether Sunshine read the page itself
     * (with how many events), left it to the scout, or couldn't read it at all. Sunshine's own note is on hover.
     */
    public static function lastCheckColumn(): TextColumn
    {
        return TextColumn::make('check_note')
            ->label(__('Last check'))
            ->wrap()
            ->placeholder(fn (Source $record): string => $record->checked_at ? __('No note') : __('Not checked yet'))
            ->tooltip(fn (Source $record): ?string => $record->read_note)
            ->description(fn (Source $record): ?string => $record->checked_at
                ? __('Checked :when · :read', [
                    'when' => $record->checked_at->diffForHumans(),
                    'read' => match (true) {
                        $record->events_read !== null => trans_choice('read by Sunshine, :count event|read by Sunshine, :count events', $record->events_read),
                        $record->page_read => __('read by the scout'),
                        default => __('page not read'),
                    },
                ])
                : null);
    }

    protected static function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSources::route('/'),
        ];
    }
}
