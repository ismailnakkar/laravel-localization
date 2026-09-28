<?php

declare(strict_types=1);

namespace Localization;

use Closure;

/** The package's language API. Not final so apps can mock it. */
class Localization
{
    /** @internal */
    public private(set) ?Closure $userLocaleSaver = null;

    /**
     * Makes $save(User $user, string $code) write the language instead of Eloquent; reads still use the user_locale
     * attribute. It gets whichever guard's user is signed in, if its row has that column; the user is synced after.
     */
    public function saveUserLocaleUsing(Closure $save): void
    {
        $this->userLocaleSaver = $save;
    }

    /**
     * The configured languages in config order; [] when fewer than two are configured.
     *
     * @return list<Language>
     */
    public function languages(): array
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return [];
        }

        $current = app()->getLocale();

        return array_map(static fn (string $code): Language => new Language($code, $code === $current), $locales->codes);
    }

    /**
     * The signed-in user's saved language; null for a guest, if unset or not in `locales`, or remember_locale off.
     */
    public function accountLanguage(): ?string
    {
        $locales = Locales::configured();

        if ($locales === null || config('localization.remember_locale') === false) {
            return null;
        }

        // The guard, not request(): a request bound before the auth provider registered has no user resolver.
        return UserLocale::of(auth()->guard()->user(), $locales);
    }

    /**
     * The language on screen when accountLanguage() differs, for a "use it for your account too?" prompt; else null
     * (an account without one is filled in). Answers post this code or accountLanguage() to localization.switch.
     */
    public function accountLanguageOffer(): ?Language
    {
        $current = app()->getLocale();
        $account = $this->accountLanguage();

        return $account === null || $account === $current || ! in_array($current, Locales::configured()->codes ?? [], true)
            ? null
            : new Language($current, true);
    }
}
