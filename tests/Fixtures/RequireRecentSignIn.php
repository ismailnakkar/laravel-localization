<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A gate ranked after the session check; its body is the locale the refusal renders in. */
final class RequireRecentSignIn
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        return new Response($this->app->getLocale(), 423);
    }
}
