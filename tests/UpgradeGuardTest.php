<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use LogicException;

final class UpgradeGuardTest extends TestCase
{
    public function test_languages_left_in_config_seo_make_route_localized_throw(): void
    {
        config(['seo.locales' => ['en', 'fr'], 'localization.locales' => []]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Route::localized(): move 'locales' and 'user_locale' from config/seo.php to config/localization.php.");

        Route::localized(static fn () => Route::get('terms', static fn () => 'terms'));
    }

    public function test_localization_check_fails_naming_each_leftover_key(): void
    {
        config(['seo.locales' => ['en', 'fr'], 'seo.user_locale' => 'locale', 'seo.entry_redirect' => ['home']]);

        $this->assertSame(1, Artisan::call('localization:check'));
        $this->assertSame(
            "languages\n  leftover keys .. FAIL seo.locales, seo.user_locale: move them to config/localization.php, laravel-seo ignores them\n\n",
            Artisan::output(),
        );

        config(['seo.locales' => null, 'seo.entry_redirect' => []]);

        $this->assertSame(1, Artisan::call('localization:check'));
        $this->assertStringContainsString('  leftover keys .. FAIL seo.user_locale: move them', Artisan::output());
    }
}
