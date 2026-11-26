<?php

namespace App\Filament\Pages;

use App\Enums\EventStatus;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use App\Filament\Tables\EventsTable;
use App\Jobs\RunSearch;
use App\Models\Event;
use App\Models\Search;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\WeekDay;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * The home page: what the weekly scans of the user's home area and other places have found, soonest first.
 */
class Upcoming extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?int $navigationSort = 0;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getSubheading(): ?string
    {
        $user = $this->user();
        $scan = $user->latestScan();

        return match (true) {
            blank($user->home_location) => __('Set your home location in Preferences to start finding events.'),
            $user->isScanning() => __('Checking for new events…'),
            $scan === null => __('Not checked yet.'),
            $scan->status === SearchStatus::Failed => __('The last check failed: :error', ['error' => str($scan->error)->limit(120)]),
            default => __('Checked :when. Sunshine checks :location every :day for the next :days days.', [
                'when' => $scan->updated_at->diffForHumans(),
                'location' => trans_choice('{0} :home|{1} :home and 1 other place|[2,*] :home and :count other places', count($user->otherPlaces()), ['home' => $user->home_location]),
                'day' => __(WeekDay::from($user->landing_day)->name),
                'days' => config('sunshine.discovery.days_ahead'),
            ]),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label(__('Check now'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->disabled(fn (): bool => blank($this->user()->home_location) || $this->user()->isScanning())
                ->action(function (): void {
                    RunSearch::dispatch(Search::discoveryFor($this->user())->id);

                    Notification::make()->success()->title(__('Checking for new events. This takes a minute or two for each place.'))->send();
                }),
            Action::make('busy_days')
                ->label(__('Busy days'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->modalHeading(__('Days you can\'t make'))
                ->modalDescription(__('Everything on these days goes off Upcoming and Plans, as with "Can\'t make it". Each event keeps its mark.'))
                ->modalSubmitActionLabel(__('Can\'t make these days'))
                ->schema([
                    CheckboxList::make('days')
                        ->hiddenLabel()
                        ->options(fn (): array => $this->busyDayOptions())
                        ->columns(2)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $ids = $this->onBusyDays()->get(['events.id', 'events.starts_on'])
                        ->filter(fn (Event $event): bool => in_array($event->starts_on->toDateString(), $data['days'], true))
                        ->modelKeys();

                    $count = Event::query()->whereKey($ids)->update(['hidden_at' => now()]);

                    Notification::make()->success()->title(trans_choice('{0} Nothing to take off on those days|{1} Took 1 event off|[2,*] Took :count events off', $count, ['count' => $count]))->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table)
            ->query(fn (): Builder => Event::query()
                ->whereHas('search', fn (Builder $query) => $query->whereBelongsTo($this->user())->where('kind', SearchKind::Discovery))
                ->whereDate('starts_on', '>=', $this->user()->today()))
            ->defaultGroup(Group::make('starts_on')
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Event $record): string => $record->starts_on->format('l, F j')))
            ->defaultPaginationPageOption(50)
            ->pushFilters([
                Filter::make('hide_not_for_us')
                    ->label(__('Hide "Not for us" and avoided'))
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query
                            ->whereNull('status')
                            ->orWhere('status', '!=', EventStatus::NotForUs))
                        ->whereDoesntHave('activity', fn (Builder $query) => $query->whereNotNull('avoided_at'))),
                // They're on Plans, and Upcoming is for finding new things.
                Filter::make('hide_interested')
                    ->label(__('Hide the ones you\'re interested in'))
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $query) => $query
                        ->whereNull('status')
                        ->orWhere('status', '!=', EventStatus::Interested))),
                Filter::make('hide_cant_make_it')
                    ->label(__('Hide the ones you can\'t make'))
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNull('hidden_at')),
            ])
            ->poll(fn (): ?string => $this->user()->isScanning() ? '5s' : null)
            ->emptyState(fn (): ?View => $this->user()->isScanning() ? view('filament.searches.searching') : null)
            ->emptyStateHeading(fn (): string => blank($this->user()->home_location) ? __('Where\'s home?') : __('Nothing coming up yet'))
            ->emptyStateDescription(fn (): string => blank($this->user()->home_location)
                ? __('Set your home location in Preferences and Sunshine will start looking.')
                : __('Press "Check now", or add pages to watch under Sources.'));
    }

    /**
     * The days ahead with something still on Upcoming or Plans, for picking the ones they can't make.
     *
     * @return array<string, string>
     */
    protected function busyDayOptions(): array
    {
        return $this->onBusyDays()
            ->toBase()
            ->selectRaw('events.starts_on as day, count(*) as events')
            ->groupBy('events.starts_on')
            ->orderBy('events.starts_on')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                CarbonImmutable::parse($row->day)->toDateString() => trans_choice(':day (1 event)|:day (:count events)', $row->events, [
                    'day' => CarbonImmutable::parse($row->day)->format('D, M j'),
                    'count' => $row->events,
                ]),
            ])
            ->all();
    }

    /**
     * What a busy day takes off: the scans' events still shown from today on, and plans from ad hoc searches too.
     *
     * @return HasManyThrough<Event, Search, User>
     */
    protected function onBusyDays(): HasManyThrough
    {
        return $this->user()->events()
            ->whereNull('events.hidden_at')
            ->whereDate('events.starts_on', '>=', $this->user()->today())
            ->where(fn (Builder $query) => $query
                ->where('searches.kind', SearchKind::Discovery)
                ->orWhere('events.status', EventStatus::Interested));
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
