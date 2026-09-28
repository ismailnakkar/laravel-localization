<?php

declare(strict_types=1);

namespace Localization;

use Closure;
use Localization\Http\ApplyLocale;
use Localization\Http\ResolveLocale;

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

    /**
     * The browser's language, to offer a visitor who told us none (no account language, no answer yet) while the
     * page is in another; else null. Offer it in its own language, posting it or the page's to localization.switch.
     */
    public function suggestion(): ?Language
    {
        $locales = Locales::configured();
        $request = request();

        if (
            $locales === null
            || config('localization.remember_locale') === false
            || ! $request->hasSession()
            || $this->accountLanguage() !== null
            || ResolveLocale::stored($request, ResolveLocale::PICKED_KEY, $locales) !== null
            || ApplyLocale::isCrawler($request)
        ) {
            return null;
        }

        $browser = $locales->preferredBy($request);

        return $browser === null || $browser === app()->getLocale() ? null : new Language($browser, false);
    }
}
