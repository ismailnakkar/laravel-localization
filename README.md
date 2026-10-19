# Laravel Localization

Localized routes and per-visitor language for Laravel: one route serves `/terms` and `/fr/terms`, and a switcher and a
suggestion banner let visitors pick their language.

## Install

```bash
composer require ismailnakkar/laravel-localization
php artisan vendor:publish --tag=localization-config
```

For [account language](#account-language), add `users.locale`, then set `'user_locale' => 'locale'`:

```bash
php artisan vendor:publish --tag=localization-migrations
php artisan migrate
```

## Configuration

| Key | Default | |
|---|---|---|
| `locales` | `[]` | Language codes, default first. Fewer than two turns everything but `numbers` off. |
| `user_locale` | `null` | The users table's language column. `null`: session only. |
| `numbers` | `null` | An ICU numbering system for Laravel's `Number`, e.g. `'latn'`. `null`: `Number` untouched. |

Codes are ISO 639-1 plus an optional script and region, cased exactly (`en`, `en-GB`, `zh-Hant`). `es-419` and `fil`
are refused, as Google ignores them in hreflang: use `es`, `tl`. A code is also the URL segment and app locale, so name
translation folders after it (`lang/pt-BR/`, not `pt_BR`).

With `'numbers' => 'latn'`, `Number` formats in the page language with Latin digits: `Number::format(1234567)` is
`1,234,567` on `/ar/…`, not `١٬٢٣٤٬٥٦٧`. A call's own `locale:` still wins.

## Usage

```php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

Each route gets a copy per language. Render `<html lang="{{ app()->getLocale() }}">` and add [the
switcher](#language).

- Call `Route::localized()` outside prefix groups, before catch-all and fallback routes. Inside, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`. Either prefix mistake throws.
- Put only pages translated into every language (and their forms' POST routes) inside.
- Link with `route()` or `action()`, which follow `app()->getLocale()`; `url()` and hard-coded paths lead to the default
  language.
- On `/fr/terms`, `Route::currentRouteName()` and `routeIs()` see `terms`; `route:list` shows `localization.fr.terms`.
- With `en` the default, `/en/…` 301s to the bare URL for localized GET pages only. Register your own `/en/…` routes
  before `Route::localized()`.
- An unmatched URL's 404 renders in `config('app.locale')` unless a `Route::fallback()` catches it.

## Language

The visitor's language is their account's, else the one they chose (switcher or [suggestion](#account-language)), else
`Accept-Language`'s, else the default. A copy renders its URL's language; other pages in `web` render the visitor's.

- Only a choice is kept: in the session, in a `localization` cookie for a year, and with `user_locale` in the account.
  Opening a copy records nothing, beyond filling an empty account once.
- Arriving from outside (another site, a bookmark, a typed URL) on a default copy 302s to the account's language,
  else the chosen one, never `Accept-Language`'s. Typed `/en/…` URLs, crawlers, signed links and internal clicks are
  exempt. Don't let a CDN cache localized pages' HTML.
- On Laravel 13, a POST catch-all inside `Route::domain()` shadows the switcher's `POST /locale`.

The switcher saves the language and returns to the same page in it. A signed page is re-signed only if its route is
named and its signature is valid under the current `app.key`, else it comes back unchanged. Labels are
`languages.{code}` in their own language (`'fr' => 'Français'` in `lang/fr/languages.php`):

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

Models without the `user_locale` column are skipped. An account without a language (a value outside `locales` counts
as none) takes the one on screen once: at a sign-in on a non-default copy (`/fr/login`, Laravel's `Login` event), else
on its next page view. Signing out (`Logout` or `CurrentDeviceLogout`) copies it into the `localization` cookie.
Implement `HasLocalePreference` returning the column so mail uses it. Run `php artisan localization:check` on deploy:
it fails (exit 1) when the default guard's users table lacks the column, and warns when it can't check.

Anyone whose language differs from the page's gets a suggestion. Render this in your layout (not error views); either
answer is kept like a switcher choice, so the banner goes:

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

## Building a language's path

`Localization\LocalizedRoute::of($route)` is `null` outside `Route::localized()`. On the `fr` copy, its
`path('/fr/terms', 'en')` is `/terms`, and `LocalizedRoute::copies($route, '/fr/terms')` (also `null` outside) is
`['path' => '/fr/terms', 'alternates' => ['en' => '/terms', 'fr' => '/fr/terms']]`, in config order.

With laravel-seo, add `$seo->alternatesUsing(\Localization\LocalizedRoute::copies(...))` to a service provider's
`boot(\Seo\Seo $seo)`, or the copies get no hreflang links and the sitemap lists only the paths you give it.

## Middleware

`ResolveLocale` (right after `StartSession`, so CSRF and throttle errors are translated) and `ApplyLocale` (right after
`\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions`, as it reads the user) join your `web` group, which
must exist. `ApplyLocale` must run after your session check:

- Session check not in your priority list by name or via `AuthenticatesSessions` (Sanctum's lacks it)? List it:
  `$middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, YourSessionCheck::class)`.
- Ranked elsewhere? `$middleware->appendToPriorityList(YourSessionCheck::class, \Localization\Http\ApplyLocale::class)`.
- Livewire: add `\Localization\Http\ResolveLocale::class` and `\Localization\Http\ApplyLocale::class` to
  `Livewire::addPersistentMiddleware()`.

## Testing

Nothing to fake: request `/fr/terms` like any URL. Routes read `locales` when they register, so set it before the app
boots.

## Upgrading

### From 0.7

- `Localization::accountLanguage()` is removed: read your user's language column, or its `preferredLocale()`, instead.
- `localization-migrations` publishes `add_locale_to_users_table` (`users.locale`). Skip it if `user_locale` already
  names a column of yours.

[MIT licensed](LICENSE).
