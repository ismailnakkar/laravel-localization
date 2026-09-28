# Laravel Localization

Localized routes and per-visitor language for Laravel: `/terms` and `/fr/terms` from one route, a switcher, and, with
[laravel-seo](https://github.com/ismailnakkar/laravel-seo) 0.5+, canonical, hreflang and sitemap entries per copy
automatically. Requires PHP 8.4+ and Laravel 12.61.1+ or 13.12+. From laravel-seo 0.4? Follow its
[UPGRADE.md](https://github.com/ismailnakkar/laravel-seo/blob/main/UPGRADE.md#from-04-to-05).

## Usage

```bash
composer require ismailnakkar/laravel-localization
php artisan vendor:publish --tag=localization-config
```

Set `'locales' => ['en', 'fr', 'es']` (default first) in `config/localization.php` and wrap the translated pages:

```php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

Render `<html lang="{{ app()->getLocale() }}">`, add [the switcher](#language) and run `php artisan localization:check` on deploy.

- Call `Route::localized()` outside prefix groups, before catch-all and fallback routes. Inside, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`.
- Only put pages translated into every language (and their forms' POST routes) inside: every copy is listed in hreflang
  and the sitemap.
- Link with `route()`; `url()` and hard-coded paths lead to the default language.
- On `/fr/terms`, `Route::currentRouteName()` and `routeIs()` see `terms`; `route:list` shows `localization.fr.terms`.
- With `en` the default, `/en/…` 301s to the bare URL only for localized GET pages; other `/en/…` paths fall through to
  your fallback, else 404. Register your own `/en/…` routes before `Route::localized()`.
- An unmatched URL's 404 renders in `config('app.locale')` unless a `Route::fallback()` catches it.

## Language

- The visitor's language is their account's, else the one they chose (switcher or [suggestion](#account-language)),
  else `Accept-Language`'s, else the default. A localized copy renders its URL's language; other pages in `web` render
  theirs.
- Opening a copy records nothing (beyond filling an empty account once, see [Account language](#account-language)).
  Only a choice is kept: in the session, in a `localization` cookie for a year (so a guest keeps it past the session),
  and with `user_locale` the account.
- Arriving from outside the site (another site, a bookmark, a typed URL) on any localized page's default copy 302s to the
  account's language, else the chosen one; never to `Accept-Language`'s, which is only suggested. Typing `/en/…`
  opens the default copy regardless. Crawlers, signed links and internal clicks are exempt. Don't let a CDN cache
  localized pages' HTML.
- On Laravel 13, a POST catch-all inside `Route::domain()` shadows the switcher's `POST /locale`.

The switcher saves the language to the session (and, with `user_locale`, the account) and returns to the same page in
that language. A signed page is re-signed only if its route is named and its signature is valid under the current
`app.key`, else it comes back unchanged. Labels are `languages.{code}` in their own language (`'fr' => 'Français'` in
`lang/fr/languages.php`):

```blade
@inject('localization', \Localization\Localization::class)
<form method="POST" action="{{ route('localization.switch') }}">
    @csrf
    <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
    @foreach ($localization->languages() as $language)
        <button name="locale" value="{{ $language->code }}" lang="{{ $language->code }}" @if ($language->current) aria-current="true" @endif>{{ __("languages.{$language->code}", locale: $language->code) }}</button>
    @endforeach
</form>
```

## Account language

Set `user_locale` to your users' language column. A member's account language outranks the session, and the switcher
saves it; an account without one gets the language on screen once (a value outside `locales` counts as none and is overwritten);
models without the column are skipped. Implement `HasLocalePreference` returning that column so mail uses it.

Anyone, members too, whose language differs from the page's gets a suggestion. Render this in your layout (not error
views); either answer is kept like a switcher choice, so the banner goes:

```blade
@inject('localization', \Localization\Localization::class)
@if ($suggestion = $localization->suggestion())
    <aside lang="{{ $suggestion->code }}">
        <form method="POST" action="{{ route('localization.switch') }}">
            @csrf
            <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
            <p>{{ __('Show this site in :language?', ['language' => __("languages.{$suggestion->code}", locale: $suggestion->code)], $suggestion->code) }}</p>
            <button name="locale" value="{{ $suggestion->code }}">{{ __('Yes', locale: $suggestion->code) }}</button>
            <button name="locale" value="{{ app()->getLocale() }}">{{ __('Use :language', ['language' => __('languages.'.app()->getLocale(), locale: app()->getLocale())], $suggestion->code) }}</button>
        </form>
    </aside>
@endif
```

The default save fires model events. To save the column your own way, call this in a service provider's
`boot(Localization $localization)`; the closure gets the signed-in model of any guard whose row has the column
(type-hint accordingly) and must return early while impersonating:

```php
$localization->saveUserLocaleUsing(fn (User $user, string $code) => app(Users::class)->setLocale($user, $code));
```

Don't render the suggestion while impersonating either: no answer is saved, so it would never go.

## Middleware

With two or more `locales`, `ResolveLocale` (right after `StartSession`, so CSRF and throttle
errors are translated) and `ApplyLocale` (right after `\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions`,
as it reads the user) join `web`. `ApplyLocale` must run after your session check:

- Session check not in your priority list by name or via `AuthenticatesSessions` (Sanctum's lacks it)? List it:
  `$middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, YourSessionCheck::class)`.
- Ranked elsewhere? `$middleware->appendToPriorityList(YourSessionCheck::class, \Localization\Http\ApplyLocale::class)`.
- Livewire: add `\Localization\Http\ResolveLocale::class` and `\Localization\Http\ApplyLocale::class` to
  `Livewire::addPersistentMiddleware()`.

## Configuration

| Key | Default | |
|---|---|---|
| `locales` | `[]` | Language codes, default first. Fewer than two turns everything off. |
| `user_locale` | `null` | The users table's language column. `null`: session only. |

Codes are ISO 639-1 plus an optional script and region, cased exactly (`en`, `en-GB`, `zh-Hant`). `es-419` and `fil`
are refused, as Google ignores them in hreflang: use `es`, `tl`. A code is also the URL segment and app locale, so name
translation folders after it (`lang/pt-BR/`, not `pt_BR`).

`localization:check` exits 1 on any FAIL. Its rows: `leftover keys` (no laravel-seo 0.4 language keys left in
`config/seo.php`), `entry_redirect` and `remember_locale` (WARN while these keys from older releases are still set),
`user_locale` (the default guard's users table has the column; WARN when it can't check).

[MIT licensed](LICENSE).
