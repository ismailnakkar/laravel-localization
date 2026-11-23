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
 * DateForm, in IntlDate::locale() and the date's own timezone.
 */
final class IntlDate
{
    /** @var array<string, IntlDateFormatter> by locale, form and timezone: building one costs far more than using it */
    private static array $formatters = [];

    /**
     * $date in $form. No date prints as null, so the page words its own fallback:
     * `IntlDate::format($at, DateForm::dayAndTime) ?? '—'`.
     *
     * @return ($date is null ? null : string)
     *
     * @throws UnexpectedValueException when ICU can't format it
     */
    public static function format(?CarbonInterface $date, DateForm $form): ?string
    {
        if ($date === null) {
            return null;
        }

        $locale = self::locale();
        $timezone = self::timezone($date);
        $formatter = self::$formatters["{$locale}|{$form->name}|{$timezone->getName()}"] ??= new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
            IntlDateFormatter::GREGORIAN,
            new IntlDatePatternGenerator($locale)->getBestPattern($form->skeleton()) ?: throw new UnexpectedValueException("ICU has no pattern for {$form->name} in {$locale}."),
        );
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
