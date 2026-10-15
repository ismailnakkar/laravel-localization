<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\Traits\Localizable;

final class NumberLocaleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('localization.numbers', 'latn');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withLocalizedRoutes(['en', 'fr', 'ar'], static function (): void {
            Route::get('count', static fn () => Number::format(1234567));
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Number::useLocale('en'); // the static outlives the app
    }

    public function test_a_page_formats_in_its_language_with_the_numbering_system(): void
    {
        $this->get('/ar/count')->assertOk()->assertContent('1,234,567'); // not ١٬٢٣٤٬٥٦٧
        $this->assertSame('ar@numbers=latn', Number::defaultLocale());

        $this->get('/fr/count')->assertOk()->assertContent("1\u{202F}234\u{202F}567");
    }

    public function test_without_numbers_number_is_left_alone(): void
    {
        config(['localization.numbers' => null]);
        Number::useLocale('de');

        $this->get('/fr/count')->assertOk()->assertContent('1.234.567');
        $this->assertSame('de', Number::defaultLocale());
    }

    public function test_with_locale_formats_in_its_language_and_restores_the_page_language(): void
    {
        app()->setLocale('fr');
        $notification = new class
        {
            use Localizable;
        };

        $this->assertSame(
            ['ar@numbers=latn', '1,234,567'],
            $notification->withLocale('ar', static fn (): array => [Number::defaultLocale(), Number::format(1234567)]),
        );
        $this->assertSame("1\u{202F}234\u{202F}567", Number::format(1234567));
    }

    public function test_booting_resets_a_locale_an_earlier_app_left(): void
    {
        Number::useLocale('ar');

        $this->refreshApplication();

        $this->assertSame('en@numbers=latn', Number::defaultLocale());
    }
}
