<?php

declare(strict_types=1);

namespace Localization;

use BackedEnum;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Localization\Http\ResolveLocale;

/** @internal */
final class UserLocale
{
    public static function of(mixed $user, Locales $locales): ?string
    {
        if (! self::hasColumn($user)) {
            return null;
        }

        $value = $user->getAttribute(self::column());
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return is_string($value) && in_array($value, $locales->codes, true) ? $value : null;
    }

    /** @phpstan-assert-if-true Model $user */
    public static function hasColumn(mixed $user): bool
    {
        $column = self::column();

        return $column !== null && $user instanceof Model && array_key_exists($column, $user->getAttributes());
    }

    /** Without a saver, saves a fresh copy so events fire. $unlessSet checks the row, as $user may be stale. */
    public static function save(mixed $user, string $code, ?Locales $unlessSet = null): void
    {
        $column = self::column();

        if ($column === null || ! $user instanceof Model) {
            return;
        }

        $fresh = $user->newQueryWithoutScopes()->find($user->getKey());

        // The row, not $user: a user created this request lacks the column until read back.
        if (! $fresh instanceof Model || ! array_key_exists($column, $fresh->getAttributes())) {
            return;
        }

        if ($unlessSet !== null && self::of($fresh, $unlessSet) !== null) {
            return;
        }

        $save = app(Localization::class)->userLocaleSaver;

        if ($save === null) {
            $fresh->forceFill([$column => $code])->save();
            $user->setAttribute($column, $fresh->getAttribute($column));
        } else {
            $save($user, $code);
            $user->setAttribute($column, $code);
        }

        $user->syncOriginalAttribute($column);
    }

    /** Sign-in lands on an unprefixed page, which would guess: an empty account takes the /fr copy it signed in on first. */
    public static function fillOnSignIn(Login $event): void
    {
        $locales = Locales::configured();
        // Only a non-default copy's URL names a language the landing page can't know. The bare copy shows the default to
        // anyone without a choice, and off a copy the request may lack that choice (a cross-site POST carries no
        // SameSite=Lax cookie; outside `web` nothing resolved it): the landing page view fills those.
        $code = LocalizedRoute::of(request()->route())?->locale;
        $guard = Auth::guard($event->guard);

        // Not a remember-me restore: nothing is on screen yet, and AuthenticateSession may still refuse it. Not a signed
        // link: its sender chose the language.
        if (
            $locales === null
            || $code === null
            || $code === $locales->default
            || self::of($event->user, $locales) !== null
            || ! in_array($code, $locales->codes, true)
            || ($guard instanceof StatefulGuard && $guard->viaRemember())
            || request()->query->has('signature')
        ) {
            return;
        }

        rescue(static fn () => self::save($event->user, $code, unlessSet: $locales));
    }

    /** Signing out ends the session that held the account's language: the cookie keeps it, as it keeps a choice. */
    public static function keepAfterSignOut(Logout|CurrentDeviceLogout $event): void
    {
        $locales = Locales::configured();
        $code = $locales === null ? null : self::of($event->user, $locales);

        if ($code !== null) {
            Cookie::queue(ResolveLocale::remember($code));
        }
    }

    public static function column(): ?string
    {
        $column = config('localization.user_locale');

        return is_string($column) && $column !== '' ? $column : null;
    }
}
