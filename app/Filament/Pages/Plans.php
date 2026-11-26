<?php

namespace App\Filament\Pages;

use App\Enums\EventStatus;
use App\Filament\Tables\EventsTable;
use App\Models\Event;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the family is interested in going to, from scans and searches alike, soonest first.
 * Once the day has passed, each one asks whether they went.
 */
class Plans extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?int $navigationSort = 1;

    public function getSubheading(): ?string
    {
        $waiting = $this->query()->whereDate('starts_on', '<', $this->user()->today())->count();

        return $waiting > 0
            ? trans_choice('{1} One has been and gone: did you go?|[2,*] :count have been and gone: did you go?', $waiting)
            : __('Things you\'re interested in going to.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table, filterByStatus: false)
            ->query(fn (): Builder => $this->query())
            ->defaultGroup(Group::make('starts_on')
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Event $record): string => $record->starts_on->format('l, F j'))
                ->getDescriptionFromRecordUsing(fn (Event $record): ?string => $record->isBefore($this->user()->today()) ? __('Did you go?') : null))
            // Only plans that have been and gone can be picked, so each past day's checkbox picks the ones still asking
            // "did you go?", and "Select all" every one of them.
            ->checkIfRecordIsSelectableUsing(fn (Event $record): bool => $record->isBefore($this->user()->today()))
            ->toolbarActions([
                EventsTable::missedBulkAction(),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('No plans yet'))
            ->emptyStateDescription(__('Mark things "Interested" on Upcoming or in a search and they\'ll gather here.'));
    }

    /**
     * @return Builder<Event>
     */
    protected function query(): Builder
    {
        return Event::query()
            ->whereHas('search', fn (Builder $query) => $query->whereBelongsTo($this->user()))
            ->where('status', EventStatus::Interested)
            ->whereNull('hidden_at');
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
