<?php

declare(strict_types=1);

namespace Localization\Tests;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Localization\Localization;
use Localization\LocalizationServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const string URL = 'http://localhost';

    protected function setUp(): void
    {
        // Laravel 12 keeps this static across tests.
        PreventRequestsDuringMaintenance::flushState();

        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [LocalizationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::URL);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32))); // EncryptCookies needs it
        $app['config']->set('database.default', 'testing');
    }

    /** Relative URLs hit the index host, not the previous request's. */
    protected function prepareUrlForRequest($uri)
    {
        return is_string($uri) && str_starts_with($uri, '/') ? self::URL . $uri : parent::prepareUrlForRequest($uri);
    }

    protected function localization(): Localization
    {
        return $this->app->make(Localization::class);
    }

    /** @param list<string> $codes the first is the default */
    protected function withLocalizedRoutes(array $codes, Closure $routes): void
    {
        config(['localization.locales' => $codes]);
        $router = $this->app->make(Router::class);
        $router->middleware('web')->group(static fn (Router $router) => $router->localized($routes));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    protected function createUsersTable(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('');
            $table->string('locale', 20)->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createAdminsTable(): void
    {
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
}
