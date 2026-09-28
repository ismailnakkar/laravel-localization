<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Localization\Http\ApplyLocale;
use Localization\Http\ResolveLocale;
use Localization\Locales;
use Localization\LocalizationServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigTest extends TestCase
{
    public function test_the_config_publishes_with_the_localization_config_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(LocalizationServiceProvider::class, 'localization-config');

        $this->assertSame([config_path('localization.php')], array_values($paths));
    }

    /** @return iterable<string, array{mixed, string}> config, message */
    public static function malformedLocales(): iterable
    {
        yield 'a duplicate code' => [['en', 'fr', 'en'], 'localization.locales: [en] is listed twice.'];
        yield 'a malformed code' => [['en', 'EN_us'], '[EN_us] is not an hreflang code'];
        yield "laravel-seo 0.3.0's code => name" => [['fr' => 'Français', 'en' => ''], 'list the codes only'];
        yield 'a code that is not a string' => [['en', 1], "localization.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations."];
        yield 'codes and names mixed' => [['en', 'fr' => 'Français'], "localization.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations."];
        yield 'not a list' => ['en,fr', "localization.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations."];
    }

    #[DataProvider('malformedLocales')]
    public function test_malformed_locales_throw(mixed $locales, string $message): void
    {
        config(['localization.locales' => $locales]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Locales::configured();
    }

    public function test_the_first_of_two_or_more_locales_is_the_default_and_one_false_or_null_is_none(): void
    {
        config(['localization.locales' => ['fr', 'en']]);
        $this->assertEquals(new Locales(['fr', 'en'], 'fr'), Locales::configured());

        config(['localization.locales' => ['en']]);
        $this->assertNull(Locales::configured());

        config(['localization.locales' => false]);
        $this->assertNull(Locales::configured());

        config(['localization.locales' => null]);
        $this->assertNull(Locales::configured());
    }

    public function test_a_list_with_gaps_reads_its_codes_in_order(): void
    {
        config(['localization.locales' => array_filter(['fr', '', 'en'])]);

        $this->assertEquals(new Locales(['fr', 'en'], 'fr'), Locales::configured());
    }

    public function test_one_language_registers_no_middleware_and_no_switch_route(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertNotContains(ResolveLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertNotContains(ApplyLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertFalse(Route::has('localization.switch'));
    }

    public function test_one_language_lists_none_and_offers_none(): void
    {
        $this->assertSame([], $this->localization()->languages());
        $this->assertNull($this->localization()->accountLanguage());
        $this->assertNull($this->localization()->accountLanguageOffer());
    }
}
