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

/**
 * Saves the visitor's language and redirects back to `to` in it.
 *
 * @internal The localization.switch route is the API.
 */
final class SwitchLocale
{
    public function __invoke(Request $request): RedirectResponse
    {
        // A route:cache built while remember_locale was on still routes here.
        abort_if(config('localization.remember_locale') === false, 404);
        $locales = Locales::configured() ?? abort(404);
        $code = (string)$request->validate(['locale' => ['required', 'string', Rule::in($locales->codes)]])['locale'];

        if ($request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $code);
        }

        UserLocale::save($request->user(), $code);

        $to = $request->input('to');
        // Drop `to`'s subdirectory base path, which redirect()->to() adds again. Read it from getRequestUri(), not
        // getBaseUrl(), which also holds a trusted X-Forwarded-Prefix.
        $path = explode('?', $request->getRequestUri(), 2)[0];
        $base = substr($path, 0, strlen($path) - strlen($request->getPathInfo()));

        if ($base !== '' && is_string($to) && str_starts_with($to, $base) && in_array(substr($to, strlen($base), 1), ['', '/', '?'], true)) {
            $to = '/' . ltrim(substr($to, strlen($base)), '/');
        }

        $target = self::target($request, $to, $code);

        return redirect()->to(self::isPath($target) ? $target : '/', 303);
    }

    /** `to` in $code. The caller re-checks that the result is a path on this host. */
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

    /**
     * $code's copy of a signed page, re-signed with its URI parameters (not ->defaults()) and expiry; null unless
     * the signature is valid and the result is this route in $code on this scheme and host: never a signing oracle.
     */
    private static function signedAgain(Request $request, Request $probe, Route $route, LocalizedRoute $localized, string $code): ?string
    {
        $name = $localized->name($route);

        if ($name === null || str_ends_with($name, '.') || str_contains($name, 'generated::')) {
            return null;
        }

        // Current key only: a previous app.key still validates requests, but renewing under it defeats rotation.
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
                // A timestamp, not an int: an int means "seconds from now" and would push the expiry out.
                is_numeric($expires) ? Carbon::createFromTimestamp((int)$expires) : null,
            );
        } catch (UrlGenerationException|InvalidArgumentException) {
            // $name now belongs to another route, one this page's parameters don't fit.
            return null;
        } finally {
            app()->setLocale($previous);
        }

        try {
            $signedRequest = Request::create($signed);
        } catch (BadRequestException) {
            return null;
        }

        // $name may name a route on another host or domain group. Check the host first: never hand out its signature.
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

    /** Open-redirect guard: a browser may read //, a backslash, a control character or a space as another host. */
    private static function isPath(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && preg_match('/[\\\\\x00-\x20\x7F]/', $value) === 0;
    }
}
