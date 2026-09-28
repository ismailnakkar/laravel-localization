<?php

declare(strict_types=1);

namespace Localization\Http;

use Closure;
use Illuminate\Http\Request;
use Localization\Locales;
use Localization\LocalizedRoute;
use Symfony\Component\HttpFoundation\Response;

/** Never reads the user, since that would sign in a remember-me cookie before AuthenticateSession checks it. */
final class ResolveLocale
{
    public const string SESSION_KEY = 'localization.browsing';

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        app()->setLocale(LocalizedRoute::of($request->route())->locale ?? self::choice($request, $locales));

        return $next($request);
    }

    /** @internal */
    public static function choice(Request $request, Locales $locales, ?string $account = null): string
    {
        $browsing = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return (is_string($browsing) && in_array($browsing, $locales->codes, true) ? $browsing : null)
            ?? $account
            ?? $locales->preferredBy($request)
            ?? $locales->default;
    }
}
