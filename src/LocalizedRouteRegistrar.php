<?php

declare(strict_types=1);

namespace Localization;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Localization\Http\RedirectToDefaultCopy;
use LogicException;

/** @internal Route::localized(): a prefixed copy per other code, the /{default}/… redirects, then the default's. */
final readonly class LocalizedRouteRegistrar
{
    private function __construct(private Router $router, private Locales $locales) {}

    /** @throws LogicException where the locale would not be the first path segment */
    public static function register(Router $router, Closure $routes): void
    {
        $locales = Locales::configured();

        if ($locales === null) {
            $router->group([], $routes);

            return;
        }

        new self($router, $locales)->registerCopies($routes);
    }

    private function registerCopies(Closure $routes): void
    {
        $last = Arr::last($this->router->getGroupStack());

        if (trim($this->router->getLastGroupPrefix(), '/') !== '' || isset($last[LocalizedRoute::ACTION])) {
            throw new LogicException('Route::localized() cannot sit inside a prefix group or another Route::localized(): the locale must be the first path segment.');
        }

        $others = array_values(array_diff($this->locales->codes, [$this->locales->default]));
        // Laravel 13 lists domain routes first, so copies aren't the tail; holding $before stops object-id reuse.
        $before = $this->router->getRoutes()->getRoutes();
        $taken = $this->router->getRoutes()->get('GET');

        foreach ($others as $code) {
            $this->router->group($this->marker($code) + ['prefix' => $code, 'as' => "localization.{$code}."], $routes);
        }

        $this->redirectDefaultPrefix($others[0], $before, $taken);

        // Default last: first match wins, and its {page} route would catch /fr/… and /en/….
        $this->router->group($this->marker($this->locales->default), $routes);

        $this->assertLocaleLeads();
    }

    /**
     * Plain action key, not Route::metadata(): survives group merging and route:cache on Laravel 12.
     *
     * @return array<string, array{codes: list<string>, default: string, locale: string}>
     */
    private function marker(string $code): array
    {
        return [LocalizedRoute::ACTION => ['codes' => $this->locales->codes, 'default' => $this->locales->default, 'locale' => $code]];
    }

    /**
     * /en/… → /…, built from the first other copy's routes, as the default's register later.
     *
     * @param  array<Route>  $before  the routes registered before this call
     * @param  array<Route>  $taken  the GET routes among them, by domain and URI
     */
    private function redirectDefaultPrefix(string $firstOther, array $before, array $taken): void
    {
        $seen = array_flip(array_map(spl_object_id(...), $before));

        foreach ($this->router->getRoutes()->getRoutes() as $copy) {
            $localized = LocalizedRoute::of($copy);

            if (isset($seen[spl_object_id($copy)]) || $localized?->locale !== $firstOther || $copy->isFallback || ! in_array('GET', $copy->methods(), true)) {
                continue;
            }

            $uri = rtrim("{$this->locales->default}/{$localized->unprefixedUri($copy)}", '/');

            if (isset($taken[$copy->getDomain() . $uri])) {
                continue;
            }

            // Domain in the action: routes are filed by domain on add. Leading `\` escapes `namespace` groups.
            $this->router->get($uri, ['uses' => '\\' . RedirectToDefaultCopy::class, RedirectToDefaultCopy::ACTION => $this->locales->default] + array_filter(['domain' => $copy->getDomain()]))
                ->where($copy->wheres);
        }
    }

    /** A route-level ->prefix() lands before the group's; only the finished URIs show it. */
    private function assertLocaleLeads(): void
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $localized = LocalizedRoute::of($route);

            if ($localized === null || $localized->isDefault()) {
                continue;
            }

            if ($route->uri() !== $localized->locale && ! str_starts_with($route->uri(), "{$localized->locale}/")) {
                throw new LogicException("Route::localized(): [{$route->uri()}] puts the locale after a route-level prefix; wrap the routes in Route::prefix(...)->group() instead.");
            }
        }
    }
}
