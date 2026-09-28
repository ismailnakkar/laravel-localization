<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/** Stands in for App\Http\Middleware\AuthenticateSession. */
final class AuthenticateSession extends LaravelAuthenticateSession {}
