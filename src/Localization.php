<?php

declare(strict_types=1);

namespace Localization;

use Closure;

/** Not final: apps mock it. */
class Localization
{
    /** @internal */
    public private(set) ?Closure $userLocaleSaver = null;

    /** Writes the language via $save(User $user, string $code) instead of Eloquent; reads still use user_locale. */
    public function saveUserLocaleUsing(Closure $save): void
    {
        $this->userLocaleSaver = $save;
    }

    /** @return list<Language> In config order; [] when fewer than two are configured. */
    public function languages(): array
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return [];
        }

        $current = app()->getLocale();

        return array_map(static fn (string $code): Language => new Language($code, $code === $current), $locales->codes);
    }

    /** The signed-in user's saved language; null for a guest, a code outside `locales`, or remember_locale off. */
    public function accountLanguage(): ?string
    {
        $locales = Locales::configured();

        if ($locales === null || config('localization.remember_locale') === false) {
            return null;
        }

        // Not request(): one bound before the auth provider registered has no user resolver.
        return UserLocale::of(auth()->guard()->user(), $locales);
    }

    /** The on-screen language to offer when accountLanguage() is set and differs; else null. */
    public function accountLanguageOffer(): ?Language
    {
        $current = app()->getLocale();
        $account = $this->accountLanguage();

        return $account === null || $account === $current || ! in_array($current, Locales::configured()->codes ?? [], true)
            ? null
            : new Language($current, true);
    }
}
