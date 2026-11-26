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
 * Everything the family went to, from scans and searches alike, newest first, with how it was.
 */
class History extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 2;

    protected ?string $subheading = 'Everything you went to, newest first.';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table, filterByStatus: false)
            ->query(fn (): Builder => Event::query()
                ->whereHas('search', fn (Builder $query) => $query->whereBelongsTo($this->user()))
                ->where('status', EventStatus::Went))
            ->defaultSort('starts_on', 'desc')
            ->defaultGroup(Group::make('starts_on')
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Event $record): string => $record->starts_on->format('l, F j, Y'))
                ->orderQueryUsing(fn (Builder $query) => $query->orderByDesc('starts_on')))
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('Nothing here yet'))
            ->emptyStateDescription(__('After something you were interested in, mark "We went" and say how it was. It shows up here, and Sunshine learns from it.'));
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
