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
    /** The last copy a URL named: fills in for pages without a language in the URL, never redirects. */
    public const string SESSION_KEY = 'localization.browsing';

    /** Set only by an answer (the switcher, a suggestion's buttons) or a member's account: known, so it redirects. */
    public const string PICKED_KEY = 'localization.picked';

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
        return $account
            ?? self::stored($request, self::PICKED_KEY, $locales)
            ?? self::stored($request, self::SESSION_KEY, $locales)
            ?? $locales->preferredBy($request)
            ?? $locales->default;
    }

    /** @internal The session's code under $key, if still configured. */
    public static function stored(Request $request, string $key, Locales $locales): ?string
    {
        $code = $request->hasSession() ? $request->session()->get($key) : null;

        return is_string($code) && in_array($code, $locales->codes, true) ? $code : null;
    }
}
