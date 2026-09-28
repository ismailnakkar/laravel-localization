<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Deliberately wrong: it overrides the URL's locale. */
final class NegotiateLocale
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->app->setLocale($request->session()->get('locale') ?? $request->getPreferredLanguage(['en', 'fr', 'ar', 'es']));

        return $next($request);
    }
}
