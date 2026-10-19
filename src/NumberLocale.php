<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Support\Number;

/** @internal */
final class NumberLocale
{
    /** Number keeps a static locale that setLocale() never touches and that outlives the app in tests. */
    public static function sync(): void
    {
        $numbers = config('localization.numbers');

        if (is_string($numbers) && $numbers !== '') {
            Number::useLocale(app()->getLocale() . '@numbers=' . $numbers);
        }
    }
}
