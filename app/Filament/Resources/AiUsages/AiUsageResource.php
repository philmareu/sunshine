<?php

namespace App\Filament\Resources\AiUsages;

use App\Filament\Resources\AiUsages\Pages\ListAiUsages;
use App\Filament\Resources\AiUsages\Tables\AiUsagesTable;
use App\Models\AiUsage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tokens, web searches and cost for every AI run.
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Usage';

    protected static ?string $modelLabel = 'usage';

    protected static ?string $pluralModelLabel = 'usage';

    protected static ?string $slug = 'usage';

    /**
     * @return Builder<AiUsage>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return AiUsagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiUsages::route('/'),
        ];
    }
}
