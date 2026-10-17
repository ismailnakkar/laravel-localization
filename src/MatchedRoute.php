<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Routing\Events\RouteMatched;

/** @internal No constructor arguments: the dispatcher container-makes [class, method] listeners. */
final class MatchedRoute
{
    public static function apply(RouteMatched $event): void
    {
        if (($localized = LocalizedRoute::of($event->route)) === null) {
            return;
        }

        app()->setLocale($localized->locale); // Octane: the request's sandbox.

        if ($localized->locale !== $localized->locales->default) {
            // Copies are named localization.{code}.… for route:cache; the matched one takes the plain name.
            $event->route->action['as'] = $localized->name($event->route);
        }
    }
}
