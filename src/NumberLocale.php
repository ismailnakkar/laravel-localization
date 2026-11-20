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
        $numbers = self::system();

        if ($numbers !== null) {
            Number::useLocale(app()->getLocale() . '@numbers=' . $numbers);
        }
    }

    /** The ICU numbering system `numbers` names for numbers and dates ('latn'); null: the locale's own digits. */
    public static function system(): ?string
    {
        $numbers = config('localization.numbers');

        return is_string($numbers) && $numbers !== '' ? $numbers : null;
    }
}
