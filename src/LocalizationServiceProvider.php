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

/** @internal Registered by package auto-discovery. */
class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/localization.php', 'localization');

        // Octane-safe singleton: it holds a closure, never resolved values.
        $this->app->singleton(Localization::class);

        // A matched listener, not middleware: it sets the locale before route middleware, also outside `web` or with
        // remember_locale off. In register() so listeners other providers add in boot() (error trackers) see it too.
        $this->app->make(Router::class)->matched(static function (RouteMatched $event): void {
            if (($localized = LocalizedRoute::of($event->route)) === null) {
                return;
            }

            app()->setLocale($localized->locale); // app() is the request's sandbox under Octane

            if ($localized->locale !== $localized->locales->default) {
                // Named localization.{code}.name, unique for route:cache; the matched copy answers to the plain name.
                $event->route->action['as'] = $localized->name($event->route);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/localization.php' => $this->app->configPath('localization.php')], 'localization-config');

        if (Locales::configured() !== null && config('localization.remember_locale') !== false) {
            // ResolveLocale right after StartSession so CSRF, throttle and auth refusals use the visitor's language.
            // ApplyLocale reads the user and may redirect: after AuthenticateSession, or last if an app omits it.
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

        // Typed mixed so a laravel-seo 0.2 call (Locales first) gets the upgrade message, not a TypeError.
        Router::macro('localized', function (mixed $routes): void {
            if (! $routes instanceof Closure) {
                throw new LogicException("Route::localized() takes only the routes closure: set the languages in config('localization.locales') as a list of codes, default first.");
            }

            /** @var Router $this */
            $locales = Locales::configured();

            if ($locales === null) {
                // Languages left in config/seo.php would otherwise leave every /fr/… URL a silent 404.
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

            // A plain action key, not Route::metadata(), survives group merging and route:cache on Laravel 12.
            $marker = static fn (string $code): array => [LocalizedRoute::ACTION => ['codes' => $locales->codes, 'default' => $locales->default, 'locale' => $code]];
            $others = array_values(array_diff($locales->codes, [$locales->default]));
            // Laravel 13 lists domain routes first, so copies aren't the tail; holding $before also stops id reuse.
            $before = $this->getRoutes()->getRoutes();
            $seen = array_flip(array_map(spl_object_id(...), $before));
            // Keyed by domain and URI, so an app's own GET on a redirect's URI is kept, not replaced by the redirect.
            $taken = $this->getRoutes()->get('GET');

            foreach ($others as $code) {
                // route:cache rejects duplicate names, but not the bare `localization.{code}.` of unnamed routes.
                $this->group($marker($code) + ['prefix' => $code, 'as' => "localization.{$code}."], $routes);
            }

            // /en/terms 301s to /terms (en the default), one per GET page, built from the first other copy as the
            // default's routes register later. Fallbacks get none, so unknown /en/… paths are not redirected.
            foreach ($this->getRoutes()->getRoutes() as $copy) {
                $localized = LocalizedRoute::of($copy);

                if (isset($seen[spl_object_id($copy)]) || $localized?->locale !== $others[0] || $copy->isFallback || ! in_array('GET', $copy->methods(), true)) {
                    continue;
                }

                $uri = rtrim("{$locales->default}/{$localized->unprefixedUri($copy)}", '/');

                if (isset($taken[$copy->getDomain() . $uri])) {
                    continue;
                }

                // Domain in the action, not ->domain(), since the collection files a route by domain as it adds it.
                // The leading backslash, as in Route::redirect(), stops a `namespace` group prefixing the class.
                $this->get($uri, ['uses' => '\\' . RedirectToDefaultCopy::class, RedirectToDefaultCopy::ACTION => $locales->default] + array_filter(['domain' => $copy->getDomain()]))
                    ->where($copy->wheres);
            }

            // Default last: first match wins, and a default {page} route would otherwise catch /fr/… and /en/….
            $this->group($marker($locales->default), $routes);

            // A route-level prefix (->prefix()) lands before the group's, and only the finished URIs show it.
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

        // Maps from any copy, since action() finds whichever copy the route collection kept.
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
