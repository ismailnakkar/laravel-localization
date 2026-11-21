<?php

declare(strict_types=1);

namespace Localization;

use Closure;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Localization\Console\CheckCommand;
use Localization\Http\ApplyLocale;
use Localization\Http\RedirectLegacyQuery;
use Localization\Http\ResolveLocale;

/** @internal */
final class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/localization.php', 'localization');

        // Singleton, not scoped: Octane must keep the boot-time closure; never store resolved values.
        $this->app->singleton(Localization::class);

        // Before any route middleware, outside `web` too. In register() so boot()-time listeners see the locale.
        $this->app->make(Router::class)->matched([MatchedRoute::class, 'apply']);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/localization.php' => $this->app->configPath('localization.php')], 'localization-config');
        $this->publishesMigrations([__DIR__ . '/../database/migrations/add_locale_to_users_table.php' => $this->app->databasePath('migrations/2026_10_19_000000_add_locale_to_users_table.php')], 'localization-migrations');

        // RedirectLegacyQuery first, ahead of the session and any redirect; ResolveLocale after StartSession, so CSRF
        // and auth refusals are translated; ApplyLocale after AuthenticateSession, as it reads the user. Unconditional:
        // each reads the config per request. Without a `web` group the Kernel throws, as the README promises them.
        $this->callAfterResolving(Kernel::class, static function (HttpKernel $kernel): void {
            $kernel->prependMiddlewareToGroup('web', RedirectLegacyQuery::class)
                ->appendMiddlewareToGroup('web', ResolveLocale::class)
                ->appendMiddlewareToGroup('web', ApplyLocale::class)
                ->addToMiddlewarePriorityAfter(StartSession::class, ResolveLocale::class)
                ->addToMiddlewarePriorityAfter(AuthenticatesSessions::class, ApplyLocale::class);
        });

        Event::listen(Login::class, [UserLocale::class, 'fillOnSignIn']);
        Event::listen([Logout::class, CurrentDeviceLogout::class], [UserLocale::class, 'keepAfterSignOut']);
        // After boot, then every setLocale(), a notification's withLocale() and its restore included.
        $this->app->booted([NumberLocale::class, 'sync']);
        Event::listen(LocaleUpdated::class, [NumberLocale::class, 'sync']);

        if (Locales::configured() !== null) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/locale.php');
        }

        Router::macro('localized', function (Closure $routes): void {
            /** @var Router $this */
            LocalizedRouteRegistrar::register($this, $routes);
        });

        $this->callAfterResolving('url', static function (UrlGenerator $url): void {
            $previous = $url->pathFormatter();

            $url->formatPathUsing(static function (string $path, ?Route $route = null) use ($previous): string {
                if (($localized = LocalizedRoute::of($route)) !== null) {
                    $locale = app()->getLocale(); // also set by NotificationSender::withLocale()
                    $path = $localized->path($path, $localized->locales->has($locale) ? $locale : $localized->locales->default);
                }

                return $previous($path, $route);
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }
}
