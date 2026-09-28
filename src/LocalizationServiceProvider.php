<?php

declare(strict_types=1);

namespace Localization;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Localization\Console\CheckCommand;
use Localization\Http\ApplyLocale;
use Localization\Http\RedirectToDefaultCopy;
use Localization\Http\ResolveLocale;
use LogicException;

/** @internal */
class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/localization.php', 'localization');

        // Singleton, not scoped: Octane must keep the boot-time closure; never store resolved values.
        $this->app->singleton(Localization::class);

        // Before any route middleware, outside `web` too. In register() so boot()-time listeners see the locale.
        $this->app->make(Router::class)->matched(static function (RouteMatched $event): void {
            if (($localized = LocalizedRoute::of($event->route)) === null) {
                return;
            }

            app()->setLocale($localized->locale); // Octane: the request's sandbox.

            if ($localized->locale !== $localized->locales->default) {
                // Copies are named localization.{code}.… for route:cache; the matched one takes the plain name.
                $event->route->action['as'] = $localized->name($event->route);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/localization.php' => $this->app->configPath('localization.php')], 'localization-config');

        if (Locales::configured() !== null && config('localization.remember_locale') !== false) {
            // ResolveLocale after StartSession, so CSRF and auth refusals are translated.
            // ApplyLocale after AuthenticateSession, as it reads the user.
            $this->callAfterResolving(Kernel::class, static function (HttpKernel $kernel): void {
                if (array_key_exists('web', $kernel->getMiddlewareGroups())) {
                    $kernel->appendMiddlewareToGroup('web', ResolveLocale::class)
                        ->appendMiddlewareToGroup('web', ApplyLocale::class)
                        ->addToMiddlewarePriorityAfter(StartSession::class, ResolveLocale::class)
                        ->addToMiddlewarePriorityAfter(AuthenticatesSessions::class, ApplyLocale::class);
                }
            });

            $this->loadRoutesFrom(__DIR__ . '/../routes/locale.php');
        }

        // mixed, not Closure: a laravel-seo 0.2 call gets the upgrade message, not a TypeError.
        Router::macro('localized', function (mixed $routes): void {
            if (! $routes instanceof Closure) {
                throw new LogicException("Route::localized() takes only the routes closure: set the languages in config('localization.locales') as a list of codes, default first.");
            }

            /** @var Router $this */
            $locales = Locales::configured();

            if ($locales === null) {
                $legacy = config('seo.locales');

                if (is_array($legacy) && count($legacy) >= 2) {
                    throw new LogicException("Route::localized(): move 'locales', 'remember_locale', 'user_locale' and 'entry_redirect' from config/seo.php to config/localization.php.");
                }

                $this->group([], $routes);

                return;
            }

            $last = Arr::last($this->getGroupStack());

            if (trim($this->getLastGroupPrefix(), '/') !== '' || isset($last[LocalizedRoute::ACTION])) {
                throw new LogicException('Route::localized() cannot sit inside a prefix group or another Route::localized(): the locale must be the first path segment.');
            }

            // Plain action key, not Route::metadata(): survives group merging and route:cache on Laravel 12.
            $marker = static fn (string $code): array => [LocalizedRoute::ACTION => ['codes' => $locales->codes, 'default' => $locales->default, 'locale' => $code]];
            $others = array_values(array_diff($locales->codes, [$locales->default]));
            // Laravel 13 lists domain routes first, so copies aren't the tail; holding $before stops object-id reuse.
            $before = $this->getRoutes()->getRoutes();
            $seen = array_flip(array_map(spl_object_id(...), $before));
            $taken = $this->getRoutes()->get('GET');

            foreach ($others as $code) {
                $this->group($marker($code) + ['prefix' => $code, 'as' => "localization.{$code}."], $routes);
            }

            // /en/… → /… redirects, built from the first other copy: the default's routes register later.
            foreach ($this->getRoutes()->getRoutes() as $copy) {
                $localized = LocalizedRoute::of($copy);

                if (isset($seen[spl_object_id($copy)]) || $localized?->locale !== $others[0] || $copy->isFallback || ! in_array('GET', $copy->methods(), true)) {
                    continue;
                }

                $uri = rtrim("{$locales->default}/{$localized->unprefixedUri($copy)}", '/');

                if (isset($taken[$copy->getDomain() . $uri])) {
                    continue;
                }

                // Domain in the action: routes are filed by domain on add. Leading `\` escapes `namespace` groups.
                $this->get($uri, ['uses' => '\\' . RedirectToDefaultCopy::class, RedirectToDefaultCopy::ACTION => $locales->default] + array_filter(['domain' => $copy->getDomain()]))
                    ->where($copy->wheres);
            }

            // Default last: first match wins, and its {page} route would catch /fr/… and /en/….
            $this->group($marker($locales->default), $routes);

            // A route-level ->prefix() lands before the group's; only the finished URIs show it.
            foreach ($this->getRoutes()->getRoutes() as $route) {
                $localized = LocalizedRoute::of($route);

                if ($localized === null || $localized->locale === $localized->locales->default) {
                    continue;
                }

                $opensWithLocale = $route->uri() === $localized->locale || str_starts_with($route->uri(), "{$localized->locale}/");

                if (! $opensWithLocale) {
                    throw new LogicException("Route::localized(): [{$route->uri()}] puts the locale after a route-level prefix; wrap the routes in Route::prefix(...)->group() instead.");
                }
            }
        });

        SeoAlternates::register($this->app);

        $this->callAfterResolving('url', static function (UrlGenerator $url): void {
            $previous = $url->pathFormatter();

            $url->formatPathUsing(static function (string $path, ?Route $route = null) use ($previous): string {
                if (($localized = LocalizedRoute::of($route)) !== null) {
                    $locale = app()->getLocale(); // also set by NotificationSender::withLocale()
                    $path = $localized->path($path, in_array($locale, $localized->locales->codes, true) ? $locale : $localized->locales->default);
                }

                return $previous($path, $route);
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }
}
