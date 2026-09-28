<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Seo\Seo;

/** @internal The only class that knows laravel-seo; answers Seo::alternatesUsing() from the route marker. */
final class SeoAlternates
{
    public static function register(Application $app): void
    {
        // Below two locales nothing is localized, and the sitemap would probe a route per loc for nothing.
        if (! class_exists(Seo::class) || Locales::configured() === null) {
            return;
        }

        $register = static fn (Seo $seo) => $seo->alternatesUsing(self::answer(...));

        // As callAfterResolving() does, since a singleton resolved before this boot fires no afterResolving callback.
        $app->afterResolving(Seo::class, $register);

        if ($app->resolved(Seo::class)) {
            $register($app->make(Seo::class));
        }
    }

    /** @return array{path: string, alternates: array<string, string>}|null null outside Route::localized() */
    public static function answer(Route $route, string $path): ?array
    {
        $localized = LocalizedRoute::of($route);

        if ($localized === null) {
            return null;
        }

        $codes = $localized->locales->codes;

        return [
            'path'       => $localized->path($path, $localized->locale),
            'alternates' => array_combine($codes, array_map(static fn (string $code): string => $localized->path($path, $code), $codes)),
        ];
    }
}
