<?php

declare(strict_types=1);

namespace Localization;

/**
 * The forms IntlDate prints a date in, each the fields of an Intl.DateTimeFormat options object. The locale picks their
 * order, forms, punctuation and, for an hour, its own clock.
 */
enum DateForm
{
    /** {day: 'numeric', month: 'short', year: 'numeric'}: "20 Nov 2026" in en-GB. */
    case day;

    /** {month: 'long', year: 'numeric'}: "November 2026" in en-GB. */
    case monthAndYear;

    /** day's options and {hour: 'numeric', minute: 'numeric'}: "20 Nov 2026, 14:05" in en-GB. */
    case dayAndTime;

    /** dayAndTime's options and {timeZoneName: 'short'}: "20 Nov 2026, 14:05 UTC" in en-GB. */
    case dayTimeAndZone;

    /** {hour: 'numeric', minute: 'numeric'}: "14:05" in en-GB. A time on a day the reader knows. */
    case time;

    /**
     * The ICU skeleton naming the form's fields; a `j` hour takes the locale's own clock.
     *
     * @internal
     */
    public function skeleton(): string
    {
        return match ($this) {
            self::day            => 'dMMMy',
            self::monthAndYear   => 'yMMMM',
            self::dayAndTime     => 'dMMMyjm',
            self::dayTimeAndZone => 'dMMMyjmz',
            self::time           => 'jm',
        };
    }
}
