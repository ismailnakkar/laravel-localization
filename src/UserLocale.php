<?php

declare(strict_types=1);

namespace Localization;

use BackedEnum;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
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
