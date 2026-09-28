<?php

declare(strict_types=1);

namespace Localization\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Localization\Locales;
use Localization\LocalizedRoute;
use Localization\UserLocale;
use LogicException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** @internal */
final class SwitchLocale
{
    public function __invoke(Request $request): RedirectResponse
    {
        // route:cache built with remember_locale on still routes here.
        abort_if(config('localization.remember_locale') === false, 404);
        $locales = Locales::configured() ?? abort(404);
        $code = (string)$request->validate(['locale' => ['required', 'string', Rule::in($locales->codes)]])['locale'];

        if ($request->hasSession()) {
            $request->session()->put(ResolveLocale::PICKED_KEY, $code);
            $request->session()->put(ResolveLocale::SESSION_KEY, $code);
        }

        UserLocale::save($request->user(), $code);

        $to = $request->input('to');
        // Strip the base path redirect()->to() re-adds. From getRequestUri(): getBaseUrl() trusts X-Forwarded-Prefix.
        $path = explode('?', $request->getRequestUri(), 2)[0];
        $base = substr($path, 0, strlen($path) - strlen($request->getPathInfo()));

        if ($base !== '' && is_string($to) && str_starts_with($to, $base) && in_array(substr($to, strlen($base), 1), ['', '/', '?'], true)) {
            $to = '/' . ltrim(substr($to, strlen($base)), '/');
        }

        $target = self::target($request, $to, $code);

        return redirect()->to(self::isPath($target) ? $target : '/', 303);
    }

    /** The caller re-checks isPath() on the result. */
    private static function target(Request $request, mixed $to, string $code): string
    {
        if (! self::isPath($to)) {
            return '/';
        }

        try {
            $probe = Request::create($request->getSchemeAndHttpHost() . $to);
            $route = app('router')->getRoutes()->match($probe);
        } catch (BadRequestException) {
            return '/';
        } catch (HttpExceptionInterface) {
            return $to;
        }

        $localized = $route->isFallback ? null : LocalizedRoute::of($route);

        if ($localized === null) {
            return $to;
        }

        if ($probe->query->has('signature')) {
            return self::signedAgain($request, $probe, $route, $localized, $code) ?? $to;
        }

        $query = (string)$probe->server->get('QUERY_STRING');

        try {
            return $localized->path($probe->getPathInfo(), $code) . ($query === '' ? '' : "?{$query}");
        } catch (LogicException) {
            return $to;
        }
    }

    /** Signing-oracle guard: null unless validly signed and landing on this route in $code. Never ->defaults(). */
    private static function signedAgain(Request $request, Request $probe, Route $route, LocalizedRoute $localized, string $code): ?string
    {
        $name = $localized->name($route);

        if ($name === null || str_ends_with($name, '.') || str_contains($name, 'generated::')) {
            return null;
        }

        // Current key only: renewing a previous-key signature defeats key rotation.
        if (! app('url')->withKeyResolver(static fn () => config('app.key'))->hasValidSignature($probe)) {
            return null;
        }

        $expires = $probe->query('expires');
        $previous = app()->getLocale();
        app()->setLocale($code);

        try {
            $signed = app('url')->signedRoute(
                $name,
                Arr::only($route->parameters(), $route->parameterNames()) + Arr::except($probe->query(), ['signature', 'expires']),
                // A Carbon, not an int: an int means seconds from now.
                is_numeric($expires) ? Carbon::createFromTimestamp((int)$expires) : null,
            );
        } catch (UrlGenerationException|InvalidArgumentException) {
            // $name may now be another route these parameters don't fit.
            return null;
        } finally {
            app()->setLocale($previous);
        }

        try {
            $signedRequest = Request::create($signed);
        } catch (BadRequestException) {
            return null;
        }

        // $name may be on another host: never hand out its signature.
        if (strcasecmp($signedRequest->getSchemeAndHttpHost(), $request->getSchemeAndHttpHost()) !== 0) {
            return null;
        }

        try {
            $landing = app('router')->getRoutes()->match($signedRequest);
        } catch (BadRequestException|HttpExceptionInterface) {
            return null;
        }

        $landingLocalized = $landing->isFallback ? null : LocalizedRoute::of($landing);

        if (
            $landingLocalized === null
            || $landingLocalized->locale !== $code
            || $landing->getDomain() !== $route->getDomain()
            || $landingLocalized->unprefixedUri($landing) !== $localized->unprefixedUri($route)
        ) {
            return null;
        }

        return $signedRequest->getRequestUri();
    }

    /** Open-redirect guard: browsers may read //, a backslash, a control char or a space as another host. */
    private static function isPath(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && preg_match('/[\\\\\x00-\x20\x7F]/', $value) === 0;
    }
}
