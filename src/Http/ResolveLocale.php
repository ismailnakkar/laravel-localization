<?php

declare(strict_types=1);

namespace Localization\Http;

use Closure;
use Illuminate\Http\Request;
use Localization\Locales;
use Localization\LocalizedRoute;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Never read the user: it would sign in a remember-me cookie before AuthenticateSession checks it. */
final class ResolveLocale
{
    /** The visitor's choice (switcher or suggestion answer) or a member's account copied in; nothing else. */
    public const string PICKED_KEY = 'localization.picked';

    /** Keeps the choice a year, past the session; set by localization.switch and by signing out. */
    public const string COOKIE = 'localization';

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
        return $account ?? self::stored($request, $locales) ?? $locales->preferredBy($request) ?? $locales->default;
    }

    /** @internal */
    public static function remember(string $code): Cookie
    {
        return cookie(self::COOKIE, $code, 60 * 24 * 365);
    }

    /** @internal */
    public static function stored(Request $request, Locales $locales): ?string
    {
        $configured = static fn (mixed $code): ?string => is_string($code) && in_array($code, $locales->codes, true) ? $code : null;

        return $configured($request->hasSession() ? $request->session()->get(self::PICKED_KEY) : null)
            ?? $configured($request->cookie(self::COOKIE));
    }
}
