<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Support\Number;

/** @internal */
final class NumberLocale
{
    /** Number keeps its own static locale: setLocale() never touches it, and in a test process it outlives the app. */
    public static function sync(): void
    {
        $numbers = config('localization.numbers');

        if (is_string($numbers) && $numbers !== '') {
            Number::useLocale(app()->getLocale() . '@numbers=' . $numbers);
        }
    }
}
