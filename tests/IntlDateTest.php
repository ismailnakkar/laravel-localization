<?php

declare(strict_types=1);

namespace Localization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Localization\DateForm;
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
     * The app locale, `date_locales`, `numbers`, then the tag dates print in and 20 November 2026 at 14:05:09 UTC in each
     * form, in DateForm::cases() order.
     *
     * @return iterable<string, array{string, array<string, string>, string|null, string, list<string>}>
     */
    public static function locales(): iterable
    {
        yield 'en mapped to en-GB' => ['en', ['en' => 'en-GB'], null, 'en-GB', [
            '20 Nov 2026', 'November 2026',
            '20 Nov 2026, 14:05', '20 Nov 2026, 14:05 UTC', '14:05',
        ]];

        yield 'fr' => ['fr', ['en' => 'en-GB'], null, 'fr', [
            '20 nov. 2026', 'novembre 2026',
            '20 nov. 2026, 14:05', '20 nov. 2026, 14:05 UTC', '14:05',
        ]];

        yield 'es' => ['es', ['en' => 'en-GB'], null, 'es', [
            '20 nov 2026', 'noviembre de 2026',
            '20 nov 2026, 14:05', '20 nov 2026, 14:05 UTC', '14:05',
        ]];

        // Arabic's own clock is 12-hour.
        yield 'ar with Latin digits' => ['ar', ['en' => 'en-GB'], 'latn', 'ar-u-nu-latn', [
            '20 نوفمبر 2026', 'نوفمبر 2026',
            '20 نوفمبر 2026، 2:05 م', '20 نوفمبر 2026، 2:05 م UTC', '2:05 م',
        ]];

        // ICU 74 puts a narrow no-break space before PM: Chrome a plain one.
        yield 'en unmapped' => ['en', [], null, 'en', [
            'Nov 20, 2026', 'November 2026',
            "Nov 20, 2026, 2:05\u{202F}PM", "Nov 20, 2026, 2:05\u{202F}PM UTC", "2:05\u{202F}PM",
        ]];
    }

    /**
     * @param  array<string, string>  $dateLocales
     * @param  list<string>  $expected
     */
    #[DataProvider('locales')]
    public function test_each_form_prints_as_the_browser_writes_it(string $appLocale, array $dateLocales, ?string $numbers, string $tag, array $expected): void
    {
        config(['localization.date_locales' => $dateLocales, 'localization.numbers' => $numbers]);
        $this->app->setLocale($appLocale);
        $afternoon = CarbonImmutable::parse('2026-11-20 14:05:09', 'UTC');

        $this->assertSame($tag, IntlDate::locale());
        $this->assertSame($expected, array_map(static fn (DateForm $form): string => IntlDate::format($afternoon, $form), DateForm::cases()));
    }

    public function test_no_date_prints_as_null(): void
    {
        $this->assertSame([null, null, null, null, null], array_map(static fn (DateForm $form): ?string => IntlDate::format(null, $form), DateForm::cases()));
    }

    public function test_a_region_prints_the_languages_short_zone_name(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB']]);
        $paris = CarbonImmutable::parse('2026-11-20 15:05:09', 'Europe/Paris');

        $this->assertSame('20 Nov 2026, 15:05 CET', IntlDate::format($paris, DateForm::dayTimeAndZone));

        $this->app->setLocale('fr');
        $this->assertSame('20 nov. 2026, 15:05 UTC+1', IntlDate::format($paris, DateForm::dayTimeAndZone));
    }

    public function test_the_locale_follows_set_locale_and_keeps_a_mapped_unicode_extension(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB', 'ar' => 'ar-u-nu-arab'], 'localization.numbers' => 'latn']);

        $this->assertSame('en-GB-u-nu-latn', IntlDate::locale());

        $this->app->setLocale('ar');
        $this->assertSame(['ar-u-nu-arab', '٢٠ نوفمبر ٢٠٢٦'], [IntlDate::locale(), IntlDate::format(CarbonImmutable::parse('2026-11-20'), DateForm::day)]);
    }

    public function test_laravels_underscored_locale_reaches_scripts_hyphenated(): void
    {
        $this->app->setLocale('pt_BR');

        $this->assertSame(['pt-BR', '20 de nov. de 2026'], [IntlDate::locale(), IntlDate::format(CarbonImmutable::parse('2026-11-20'), DateForm::day)]);
    }

    public function test_a_date_prints_in_its_own_timezone(): void
    {
        // 19 November at 11:00 in UTC.
        $auckland = CarbonImmutable::parse('2026-11-20', 'Pacific/Auckland');

        $this->assertSame(['Nov 20, 2026', 'Nov 19, 2026'], [IntlDate::format($auckland, DateForm::day), IntlDate::format($auckland->utc(), DateForm::day)]);
    }

    public function test_an_abbreviated_timezone_prints_at_its_offset(): void
    {
        config(['localization.date_locales' => ['en' => 'en-GB']]);

        // ICU refuses "Z" and "CEST", and reads "PST" as Los Angeles, where 23:30 on 31 July is 00:30 on 1 August.
        $this->assertSame(['10:00', '10:00', '23:30'], [
            IntlDate::format(CarbonImmutable::parse('2026-11-20T10:00:00Z'), DateForm::time),
            IntlDate::format(CarbonImmutable::parse('2026-07-20 10:00:00 CEST'), DateForm::time),
            IntlDate::format(CarbonImmutable::parse('2026-07-31 23:30:00 PST'), DateForm::time),
        ]);
    }
}
