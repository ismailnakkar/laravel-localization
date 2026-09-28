<?php

declare(strict_types=1);

namespace Localization;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/** @internal The user's language, in the config('localization.user_locale') attribute. */
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

    /**
     * False for a non-Eloquent user or a model lacking the column, e.g. one created this request, not yet read back.
     *
     * @phpstan-assert-if-true Model $user
     */
    public static function hasColumn(mixed $user): bool
    {
        $column = self::column();

        return $column !== null && $user instanceof Model && array_key_exists($column, $user->getAttributes());
    }

    /**
     * Via saveUserLocaleUsing()'s closure, else on a fresh copy so no other change to $user is written and model
     * events fire. $user is synced after. $unlessSet skips a row that has one of its codes: $user may be stale.
     */
    public static function save(mixed $user, string $code, ?Locales $unlessSet = null): void
    {
        $column = self::column();

        if ($column === null || ! $user instanceof Model) {
            return;
        }

        $fresh = $user->newQueryWithoutScopes()->find($user->getKey());

        // The row, not $user: a user created in this request lacks the column until read back.
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

    public static function column(): ?string
    {
        $column = config('localization.user_locale');

        return is_string($column) && $column !== '' ? $column : null;
    }
}
