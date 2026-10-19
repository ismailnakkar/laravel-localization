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

    /** @return list<Language> in config order; [] with fewer than two configured */
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
     * The visitor's language (account, else choice, else browser) while the page is in another, else null. Post it
     * or the page's to localization.switch: either is kept. Never on a signed page: its sender chose the language.
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

        // Stored first: ApplyLocale copied in the account POST /locale saves to; a guard swapped later (impersonation)
        // would suggest a language no answer could store.
        // Not $request->user(): one bound before the auth provider registered has no user resolver.
        $theirs = ResolveLocale::stored($request, $locales) ?? UserLocale::of(auth()->guard()->user(), $locales) ?? $locales->preferredBy($request);

        return $theirs === null || $theirs === app()->getLocale() ? null : new Language($theirs, false);
    }
}
