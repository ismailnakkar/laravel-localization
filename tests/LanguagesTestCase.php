<?php

declare(strict_types=1);

namespace Localization\Tests;

use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Localization\Tests\Fixtures\User;

/** Four languages, the fixture User as accounts, and no Accept-Language unless a test sends one. */
abstract class LanguagesTestCase extends TestCase
{
    protected const string BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

    protected function getPackageProviders($app): array
    {
        // Testbench skips package discovery, and Carbon follows the app locale only through its own provider.
        return [CarbonServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('localization.locales', ['en', 'fr', 'ar', 'es']);
        $app['config']->set('localization.user_locale', 'locale');
        $app['config']->set('localization.entry_redirect', ['home']);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
        // Symfony's test requests otherwise send `Accept-Language: en-us,en;q=0.5` and `User-Agent: Symfony`.
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Accept-Language' => '']);
    }
}
