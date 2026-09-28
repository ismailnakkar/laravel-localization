<?php

declare(strict_types=1);

namespace Localization\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Localization\LocalizedRoute;

/** @internal 301s `/en/terms`, en being the default, to `/terms`. */
final class RedirectToDefaultCopy
{
    /** @internal Route-action key with the default code, so ApplyLocale counts the redirect as opening that copy. */
    public const string ACTION = 'localization_default_redirect';

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Route $route */
        $route = $request->route();
        // One leading slash, or `/en//host` would redirect to `//host`, which a browser reads as another host.
        $path = '/' . ltrim(LocalizedRoute::withoutPrefix($request->getPathInfo(), (string)$route->getAction(self::ACTION)), '/');
        $query = (string)$request->server->get('QUERY_STRING');

        // Symfony sends a 301 without Cache-Control unless given one, so a browser caches it and the next /en skips
        // ApplyLocale, which records the choice.
        return redirect()->to($path . ($query === '' ? '' : "?{$query}"), 301, ['Cache-Control' => 'no-cache, private']);
    }
}
