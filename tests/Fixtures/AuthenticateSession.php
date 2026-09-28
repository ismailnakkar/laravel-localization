<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/** An app's own AuthenticateSession subclass, as in App\Http\Middleware. */
final class AuthenticateSession extends LaravelAuthenticateSession {}
