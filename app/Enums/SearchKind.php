<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchKind: string implements HasLabel
{
    /** A search the user started for a place and dates of their choosing. */
    case AdHoc = 'ad_hoc';

    /** A scheduled scan of the user's home area that feeds Upcoming. */
    case Discovery = 'discovery';

    public function getLabel(): string
    {
        return match ($this) {
            self::AdHoc => __('Search'),
            self::Discovery => __('Scan'),
        };
    }
}
