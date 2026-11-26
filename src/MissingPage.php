<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;

/**
 * @internal A path no route matched, or only a Route::fallback(), takes its first segment's language when that is a
 * code other than the default, so its 404 reads in the copy's language whichever view or handler renders it. No
 * constructor arguments: the dispatcher container-makes [class, method] listeners.
 */
final class MissingPage
{
    /** The request attribute holding the locale from before routing, for a route that matches after all. */
    private const string LOCALE_BEFORE = 'localization.locale_before_routing';

    /** Before matching: a path no route matches goes straight to the exception handler, with no event after it. */
    public static function routing(Routing $event): void
    {
        $locales = Locales::configured();

        if ($locales === null || ($code = self::locale($event->request, $locales)) === null) {
            return;
        }

        $event->request->attributes->set(self::LOCALE_BEFORE, app()->getLocale());
        app()->setLocale($code);
    }

    /** A route other than the fallback matched: it keeps the locale it had, unless localized (MatchedRoute sets it). */
    public static function matched(RouteMatched $event): void
    {
        $before = $event->request->attributes->get(self::LOCALE_BEFORE);

        if (is_string($before) && ! $event->route->isFallback && LocalizedRoute::of($event->route) === null) {
            app()->setLocale($before);
        }
    }

    /**
     * A missing page's language; null once a route other than the fallback matched, for an unknown first segment, and
     * for the default's code: `/en/…` names no copy.
     */
    public static function locale(Request $request, Locales $locales): ?string
    {
        $route = $request->route();
        $code = $request->segment(1);

        if (($route instanceof Route && ! $route->isFallback) || $code === $locales->default || ! $locales->has($code)) {
            return null;
        }

        return $code;
    }
}
