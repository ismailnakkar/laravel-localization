<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Localization\Tests\Fixtures\User;

final class CheckCommandTest extends TestCase
{
    public function test_the_language_config_is_checked_where_boot_cannot(): void
    {
        config([
            'localization.locales'        => ['en', 'fr'],
            'localization.entry_redirect' => ['home'],
            'localization.user_locale'    => 'locale',
            'auth.providers.users.model'  => User::class,
        ]);
        $this->createUsersTable();

        [$code, $output] = $this->check();

        $this->assertSame(<<<'TXT'
            languages
              entry_redirect . WARN ignored since 0.2, remove it: every localized page now redirects to a known language
              user_locale .... PASS users.locale


            TXT, $output);
        $this->assertSame(0, $code);

        config(['localization.user_locale' => 'missing']);
        [$code, $output] = $this->check();

        $this->assertStringContainsString('  user_locale .... FAIL users has no column [missing]', $output);
        $this->assertSame(1, $code);

        config(['localization.user_locale' => 'locale']);
        [$code] = $this->check();

        $this->assertSame(0, $code);
    }

    public function test_the_account_column_warns_while_the_locale_is_not_remembered(): void
    {
        config([
            'localization.locales'         => ['en', 'fr'],
            'localization.remember_locale' => false,
            'localization.entry_redirect'  => ['home'],
            'localization.user_locale'     => 'locale',
            'auth.providers.users.model'   => User::class,
        ]);

        [$code, $output] = $this->check();

        $this->assertStringContainsString(<<<'TXT'
            languages
              entry_redirect . WARN ignored since 0.2, remove it: every localized page now redirects to a known language
              user_locale .... WARN ignored while remember_locale is false

            TXT, $output);
        $this->assertSame(0, $code);

        config(['localization.entry_redirect' => []]);
        [, $output] = $this->check();

        $this->assertStringContainsString("languages\n  user_locale .... WARN ignored while remember_locale is false\n", $output);
        $this->assertStringNotContainsString('entry_redirect', $output);

        config(['localization.user_locale' => null]);

        $this->assertSame([0, ''], $this->check());
    }

    public function test_the_user_column_warns_when_the_database_cannot_be_reached(): void
    {
        config([
            'database.connections.gone'  => ['driver' => 'sqlite', 'database' => '/nonexistent/localization.sqlite', 'prefix' => ''],
            'database.default'           => 'gone',
            'localization.user_locale'   => 'locale',
            'auth.providers.users.model' => User::class,
        ]);
        $this->withLocalizedRoutes(['en', 'fr'], static fn () => Route::get('/', static fn () => 'home'));

        [$code, $output] = $this->check();

        $this->assertStringContainsString('  user_locale .... WARN could not reach the database to check users.locale', $output);
        $this->assertSame(0, $code);
    }

    public function test_one_language_prints_nothing(): void
    {
        config(['localization.entry_redirect' => ['home'], 'localization.user_locale' => 'locale']);

        $this->assertSame([0, ''], $this->check());
    }

    public function test_two_languages_without_a_redirect_or_an_account_column_print_nothing(): void
    {
        config(['localization.locales' => ['en', 'fr']]);

        $this->assertSame([0, ''], $this->check());
    }

    /** @return array{int, string} exit code, output */
    private function check(): array
    {
        $code = Artisan::call('localization:check');

        return [$code, Artisan::output()];
    }
}
