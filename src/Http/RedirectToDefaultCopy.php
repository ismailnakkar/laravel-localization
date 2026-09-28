<?php

declare(strict_types=1);

namespace Localization\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Localization\LocalizedRoute;

/** @internal */
final class RedirectToDefaultCopy
{
    /** @internal */
    public const string ACTION = 'localization_default_redirect';

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Route $route */
        $route = $request->route();
        // One leading slash: `/en//host` must not redirect to `//host`, another host.
        $path = '/' . ltrim(LocalizedRoute::withoutPrefix($request->getPathInfo(), (string)$route->getAction(self::ACTION)), '/');
        $query = (string)$request->server->get('QUERY_STRING');

        // No-cache: a browser-cached 301 would skip ApplyLocale, whose flash keeps the landing from being entry-redirected.
        return redirect()->to($path . ($query === '' ? '' : "?{$query}"), 301, ['Cache-Control' => 'no-cache, private']);
    }
}
