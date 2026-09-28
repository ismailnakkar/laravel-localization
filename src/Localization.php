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

    /** The signed-in user's saved language; null for a guest or a code outside `locales`. */
    public function accountLanguage(): ?string
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return null;
        }

        // Not request(): one bound before the auth provider registered has no user resolver.
        return UserLocale::of(auth()->guard()->user(), $locales);
    }

    /**
     * The visitor's language (their account's, else their choice, else their browser's) while the page is in another;
     * else null. Offer it in its own language, posting it or the page's to localization.switch: either answer is kept.
     * Never on a signed page: its sender chose the language, and one that can't be signed again would come back as is.
     */
    public function suggestion(): ?Language
    {
        $locales = Locales::configured();
        $request = request();

        if (
            $locales === null
            || ! $request->hasSession()
            || $request->query->has('signature')
            || ApplyLocale::isCrawler($request)
        ) {
            return null;
        }

        // The choice first: ApplyLocale copied in the account of the user it saw, the one POST /locale saves to, where
        // a guard swapped later (impersonation) would offer a language no answer could store.
        $theirs = ResolveLocale::stored($request, $locales) ?? $this->accountLanguage() ?? $locales->preferredBy($request);

        return $theirs === null || $theirs === app()->getLocale() ? null : new Language($theirs, false);
    }
}
