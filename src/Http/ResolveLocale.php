<?php

declare(strict_types=1);

namespace Localization\Http;

use Closure;
use Illuminate\Http\Request;
use Localization\Locales;
use Localization\LocalizedRoute;
use Symfony\Component\HttpFoundation\Response;

/** Never read the user: it would sign in a remember-me cookie before AuthenticateSession checks it. */
final class ResolveLocale
{
    /** The visitor's choice (the switcher, a suggestion's answer), or a member's account copied in. Nothing else. */
    public const string PICKED_KEY = 'localization.picked';

    /** The same choice for a year, so a guest keeps it past the session: set only by localization.switch. */
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

    /** @internal What the visitor told us first, then what we guess. */
    public static function choice(Request $request, Locales $locales, ?string $account = null): string
    {
        return $account ?? self::stored($request, $locales) ?? $locales->preferredBy($request) ?? $locales->default;
    }

    /** @internal The visitor's choice, from the session else the cookie, if still configured. */
    public static function stored(Request $request, Locales $locales): ?string
    {
        $configured = static fn (mixed $code): ?string => is_string($code) && in_array($code, $locales->codes, true) ? $code : null;

        return $configured($request->hasSession() ? $request->session()->get(self::PICKED_KEY) : null)
            ?? $configured($request->cookie(self::COOKIE));
    }
}
