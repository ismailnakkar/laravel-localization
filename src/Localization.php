<?php

declare(strict_types=1);

namespace Localization;

use Closure;
use Illuminate\Http\Request;
use Localization\Http\ApplyLocale;
use Localization\Http\QueryString;
use Localization\Http\ResolveLocale;

/** Not final: apps mock it. */
class Localization
{
    /** @internal */
    public private(set) ?Closure $userLocaleSaver = null;

    /** @var (Closure(Request): bool)|null */
    private ?Closure $readOnlyWhen = null;

    /** Writes the language via $save(User $user, string $code) instead of Eloquent; reads still use user_locale. */
    public function saveUserLocaleUsing(Closure $save): void
    {
        $this->userLocaleSaver = $save;
    }

    /**
     * While $readOnly(Request $request) returns true, such as while an admin views the site as a member, the account's
     * language is never saved and suggestion() is null: no answer could be kept, so the banner would never go.
     */
    public function readOnlyWhen(Closure $readOnly): void
    {
        $this->readOnlyWhen = $readOnly;
    }

    /** @internal */
    public function isReadOnly(): bool
    {
        return $this->readOnlyWhen !== null && ($this->readOnlyWhen)(request());
    }

    /** @return list<Language> in config order; [] with fewer than two configured */
    public function languages(): array
    {
        $locales = Locales::configured();

        return $locales === null ? [] : array_map(Language::of(...), $locales->codes);
    }

    /**
     * The visitor's language (account, else choice, else browser) while the page is in another, else null. Post it
     * or the page's to localization.switch: either is kept. Never on a signed page (its sender chose the language) nor
     * while read-only.
     */
    public function suggestion(): ?Language
    {
        $locales = Locales::configured();
        $request = request();

        if (
            $locales === null
            || ! $request->hasSession()
            || QueryString::isSigned($request)
            || ApplyLocale::isCrawler($request)
            || $this->isReadOnly()
        ) {
            return null;
        }

        // The stored choice first: ApplyLocale copied into it the language of the account an answer saves to; the
        // guard's user may since have been swapped (impersonation), and its language is one no answer could store.
        // Not $request->user(): a request bound before the auth provider registered has no user resolver.
        $theirs = ResolveLocale::stored($request, $locales) ?? UserLocale::of(auth()->guard()->user(), $locales) ?? $locales->preferredBy($request);

        return $theirs === null || $theirs === app()->getLocale() ? null : new Language($theirs, false);
    }
}
