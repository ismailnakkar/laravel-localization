<?php

declare(strict_types=1);

namespace Localization;

use Carbon\CarbonInterface;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use UnexpectedValueException;

/**
 * A date as a browser's Intl.DateTimeFormat writes the same fields in the same locale: ICU's best pattern for a
 * skeleton, in IntlDate::locale() and the date's own timezone. A skeleton names the fields only; the locale picks their
 * order, forms, punctuation and, for a `j` hour, its own clock.
 */
final class IntlDate
{
    /** {day: 'numeric', month: 'short'}: "20 Nov" in en-GB. */
    public const string DAY_AND_MONTH = 'dMMM';

    /** {day: 'numeric', month: 'short', year: 'numeric'}: "20 Nov 2026" in en-GB. */
    public const string DAY_MONTH_AND_YEAR = 'dMMMy';

    /** {month: 'long', year: 'numeric'}: "November 2026" in en-GB. */
    public const string MONTH_AND_YEAR = 'yMMMM';

    /** DAY_MONTH_AND_YEAR's options and {hour: 'numeric', minute: 'numeric'}: "20 Nov 2026, 14:05" in en-GB. */
    public const string DATE_AND_TIME = 'dMMMyjm';

    /** DATE_AND_TIME's options and {second: 'numeric'}: "20 Nov 2026, 14:05:09" in en-GB. */
    public const string DATE_AND_TIME_WITH_SECONDS = 'dMMMyjms';

    /** {hour: 'numeric', minute: 'numeric'}: "14:05" in en-GB. A time on a day the reader knows. */
    public const string TIME = 'jm';

    /** @var array<string, IntlDateFormatter> by locale, skeleton and timezone: building one costs far more than using it */
    private static array $formatters = [];

    /**
     * $date with the fields $skeleton names, one of the constants or any ICU skeleton.
     *
     * @throws UnexpectedValueException when ICU can't format it
     */
    public static function format(CarbonInterface $date, string $skeleton): string
    {
        $locale = self::locale();
        $timezone = self::timezone($date);
        $formatter = self::$formatters["{$locale}|{$skeleton}|{$timezone->getName()}"] ??= new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
            IntlDateFormatter::GREGORIAN,
            new IntlDatePatternGenerator($locale)->getBestPattern($skeleton) ?: throw new UnexpectedValueException("ICU has no pattern for {$skeleton} in {$locale}."),
        );

        // Only false fails: a minute or second skeleton prints "0" on the hour.
        $formatted = $formatter->format($date);

        return $formatted !== false ? $formatted : throw new UnexpectedValueException($formatter->getErrorMessage());
    }

    /**
     * The BCP 47 tag dates print in ("en-GB", "ar-u-nu-latn"): the app locale through `date_locales`, with the
     * `numbers` system as its -u-nu- extension. Hand it to a script's Intl.DateTimeFormat so its dates read the same.
     */
    public static function locale(): string
    {
        $appLocale = app()->getLocale();
        // Laravel's pt_BR is pt-BR to a browser; ICU reads both.
        $tag = str_replace('_', '-', config()->string("localization.date_locales.{$appLocale}", $appLocale));
        $numbers = NumberLocale::system();

        // A tag with its own Unicode extension ('ar-u-nu-arab') is used as written: a second -u- is no BCP 47 tag.
        return $numbers === null || str_contains($tag, '-u-') ? $tag : "{$tag}-u-nu-{$numbers}";
    }

    /**
     * $date's timezone where it names a region (Europe/Paris, UTC), else its offset: ICU refuses most abbreviations
     * ("Z" from a Carbon parsed from '…Z', "CEST") and reads a few as regions ("PST" as Los Angeles, a summer hour later
     * than PHP's fixed -08:00).
     */
    private static function timezone(CarbonInterface $date): DateTimeZone
    {
        $timezone = $date->getTimezone();

        return $timezone->getLocation() !== false ? $timezone : new DateTimeZone($date->format('P'));
    }
}
