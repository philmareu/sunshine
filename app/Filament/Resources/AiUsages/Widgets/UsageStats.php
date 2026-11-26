<?php

namespace App\Filament\Resources\AiUsages\Widgets;

use App\Models\AiUsage;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class UsageStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            $this->stat(__('This month'), auth()->user()->today()->startOfMonth()->utc()),
            $this->stat(__('Last 30 days'), now()->subDays(30)),
            $this->stat(__('All time')),
        ];
    }

    protected function stat(string $label, ?CarbonInterface $since = null): Stat
    {
        $totals = AiUsage::query()
            ->whereBelongsTo(auth()->user())
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->selectRaw('count(*) as runs, coalesce(sum(cost), 0) as cost, coalesce(sum(input_tokens + output_tokens), 0) as tokens')
            ->first();

        return Stat::make($label, Number::currency((float) $totals->cost, 'USD'))
            ->description(trans_choice(':runs run|:runs runs', $totals->runs, ['runs' => $totals->runs]).' · '.Number::abbreviate((int) $totals->tokens).' '.__('tokens'));
    }
}
