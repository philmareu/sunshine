<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SearchStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Queued'),
            self::Running => __('Searching'),
            self::Completed => __('Done'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Running]);
    }
}
