<?php

declare(strict_types=1);

namespace Localization\Http;

use Closure;
use Illuminate\Http\Request;
use Localization\LocalizedRoute;
use Symfony\Component\HttpFoundation\Response;

/** First in `web`: answers before the session starts and before ApplyLocale's entry redirect picks a copy. */
final class RedirectLegacyQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('localization.legacy_query');
        $localized = LocalizedRoute::of($request->route());

        if (
            ! is_string($key)
            || $localized === null
            || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))
            || QueryString::isSigned($request)
        ) {
            return $next($request);
        }

        $value = $request->query($key);
        $code = is_string($value) ? $localized->locales->codeFor($value) : null;

        if ($code === null) {
            return $next($request);
        }

        $query = self::withoutParameter(QueryString::of($request), $key);

        return redirect()->to(QueryString::append($localized->path($request->getPathInfo(), $code), $query), 301);
    }

    /** Filtered as spelt, not rebuilt from query(), which reads `a.b` as `a_b`. */
    private static function withoutParameter(string $query, string $name): string
    {
        return implode('&', array_filter(explode('&', $query), static fn (string $pair): bool => ! self::names($pair, $name)));
    }

    /** As PHP's parser, which fills query(), reads it: `+lang`, `%20lang` and `lang%00` all name `lang`. */
    private static function names(string $pair, string $name): bool
    {
        parse_str($pair, $parsed);

        return array_key_exists($name, $parsed);
    }
}
