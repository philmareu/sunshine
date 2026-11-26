<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where an event is in its life for this family. The scout learns from all but Missed.
 */
enum EventStatus: string implements HasColor, HasIcon, HasLabel
{
    case Interested = 'interested';
    case NotForUs = 'not_for_us';
    case Went = 'went';
    case Missed = 'missed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Interested => __('Interested'),
            self::NotForUs => __('Not for us'),
            self::Went => __('Went'),
            self::Missed => __('Didn\'t go'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Interested => 'primary',
            self::NotForUs => 'danger',
            self::Went => 'success',
            self::Missed => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Interested => Heroicon::OutlinedHandThumbUp,
            self::NotForUs => Heroicon::OutlinedHandThumbDown,
            self::Went => Heroicon::OutlinedCheckCircle,
            self::Missed => Heroicon::OutlinedMinusCircle,
        };
    }
}
