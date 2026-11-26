<?php

namespace App\Filament\Resources\AiUsages\Pages;

use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\AiUsages\Widgets\UsageStats;
use Filament\Resources\Pages\ListRecords;

class ListAiUsages extends ListRecords
{
    protected static string $resource = AiUsageResource::class;

    protected ?string $subheading = 'Costs are estimates from list prices in config/sunshine.php.';

    protected function getHeaderWidgets(): array
    {
        return [
            UsageStats::class,
        ];
    }
}
