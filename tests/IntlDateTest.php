<?php

declare(strict_types=1);

namespace Localization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Localization\IntlDate;
use PHPUnit\Framework\Attributes\DataProvider;

/** Dates read as a browser's Intl.DateTimeFormat writes the same fields in the same locale. */
final class IntlDateTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Number::useLocale('en'); // `numbers` resets the static on setLocale(), and it outlives the app
    }

    /**
     * The app locale, `date_locales`, `numbers`, then the tag dates print in and 20 November 2026 at 14:05:09 through
     * each constant.
     *
     * @return iterable<string, array{string, array<string, string>, string|null, string, list<string>}>
     */
    public static function locales(): iterable
    {
        yield 'en mapped to en-GB' => ['en', ['en' => 'en-GB'], null, 'en-GB', [
            '20 Nov', '20 Nov 2026', 'November 2026',
            '20 Nov 2026, 14:05', '14:05',
        ]];

        yield 'fr' => ['fr', ['en' => 'en-GB'], null, 'fr', [
            '20 nov.', '20 nov. 2026', 'novembre 2026',
            '20 nov. 2026, 14:05', '14:05',
        ]];

        yield 'es' => ['es', ['en' => 'en-GB'], null, 'es', [
            '20 nov', '20 nov 2026', 'noviembre de 2026',
            '20 nov 2026, 14:05', '14:05',
        ]];

        // Arabic's own clock is 12-hour.
        yield 'ar with Latin digits' => ['ar', ['en' => 'en-GB'], 'latn', 'ar-u-nu-latn', [
            '20 نوفمبر', '20 نوفمبر 2026', 'نوفمبر 2026',
            '20 نوفمبر 2026، 2:05 م', '2:05 م',
        ]];

        // ICU 74 puts a narrow no-break space before PM: Chrome a plain one.
        yield 'en unmapped' => ['en', [], null, 'en', [
            'Nov 20', 'Nov 20, 2026', 'November 2026',
            "Nov 20, 2026, 2:05\u{202F}PM", "2:05\u{202F}PM",
        ]];
    }

    /**
     * @param  array<string, string>  $dateLocales
     * @param  list<string>  $expected
     */
    #[DataProvider('locales')]
    public function test_each_constant_prints_as_the_browser_writes_it(string $appLocale, array $dateLocales, ?string $numbers, string $tag, array $expected): void
    {
        config(['localization.date_locales' => $dateLocales, 'localization.numbers' => $numbers]);
        $this->app->setLocale($appLocale);
        $afternoon = CarbonImmutable::parse('2026-11-20 14:05:09', 'UTC');

        $this->assertSame($tag, IntlDate::locale());
        $this->assertSame($expected, array_map(static fn (string $skeleton): string => IntlDate::format($afternoon, $skeleton), [
            IntlDate::DAY_AND_MONTH,
            IntlDate::DAY_MONTH_AND_YEAR,
            IntlDate::MONTH_AND_YEAR,
            IntlDate::DATE_AND_TIME,
            IntlDate::TIME,
        ]));
    }

    public function test_the_named_forms_print_their_constants_and_no_date_as_null(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB']]);
        $afternoon = CarbonImmutable::parse('2026-11-20 14:05:09', 'UTC');

        $this->assertSame(
            ['20 Nov 2026', '20 Nov 2026, 14:05', '14:05'],
            [IntlDate::day($afternoon), IntlDate::dayAndTime($afternoon), IntlDate::time($afternoon)],
        );
        $this->assertSame(
            [null, null, null, null],
            [IntlDate::day(null), IntlDate::dayAndTime(null), IntlDate::time(null), IntlDate::format(null, IntlDate::DAY_AND_MONTH)],
        );
    }

    public function test_zone_names_the_zone_where_the_language_puts_it(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB'], 'localization.numbers' => 'latn']);
        $afternoon = CarbonImmutable::parse('2026-11-20 14:05:09', 'UTC');

        $this->assertSame('20 Nov 2026, 14:05 UTC', IntlDate::format($afternoon, IntlDate::DATE_AND_TIME . IntlDate::ZONE));

        $this->app->setLocale('ar');
        $this->assertSame('20 نوفمبر 2026، 2:05 م UTC', IntlDate::format($afternoon, IntlDate::DATE_AND_TIME . IntlDate::ZONE));
    }

    public function test_the_locale_follows_set_locale_and_keeps_a_mapped_unicode_extension(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB', 'ar' => 'ar-u-nu-arab'], 'localization.numbers' => 'latn']);

        $this->assertSame('en-GB-u-nu-latn', IntlDate::locale());

        $this->app->setLocale('ar');
        $this->assertSame(['ar-u-nu-arab', '٢٠ نوفمبر'], [IntlDate::locale(), IntlDate::format(CarbonImmutable::parse('2026-11-20'), IntlDate::DAY_AND_MONTH)]);
    }

    public function test_laravels_underscored_locale_reaches_scripts_hyphenated(): void
    {
        $this->app->setLocale('pt_BR');

        $this->assertSame(['pt-BR', '20 de nov.'], [IntlDate::locale(), IntlDate::format(CarbonImmutable::parse('2026-11-20'), IntlDate::DAY_AND_MONTH)]);
    }

    public function test_a_date_prints_in_its_own_timezone(): void
    {
        // 19 November at 11:00 in UTC.
        $auckland = CarbonImmutable::parse('2026-11-20', 'Pacific/Auckland');

        $this->assertSame(['Nov 20', 'Nov 19'], [IntlDate::format($auckland, IntlDate::DAY_AND_MONTH), IntlDate::format($auckland->utc(), IntlDate::DAY_AND_MONTH)]);
    }

    public function test_an_abbreviated_timezone_prints_at_its_offset(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB']]);

        // ICU refuses "Z" and "CEST", and reads "PST" as Los Angeles, where 23:30 on 31 July is 00:30 on 1 August.
        $this->assertSame(['10:00', '10:00', '23:30'], [
            IntlDate::format(CarbonImmutable::parse('2026-11-20T10:00:00Z'), IntlDate::TIME),
            IntlDate::format(CarbonImmutable::parse('2026-07-20 10:00:00 CEST'), IntlDate::TIME),
            IntlDate::format(CarbonImmutable::parse('2026-07-31 23:30:00 PST'), IntlDate::TIME),
        ]);
    }

    public function test_a_minute_on_the_hour_prints_zero(): void
    {
        $this->assertSame('0', IntlDate::format(CarbonImmutable::parse('2026-11-20 14:00:00'), 'm'));
    }
}
