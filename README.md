# Laravel Localization

Localized routes and per-visitor language for Laravel. One route serves `/terms` and `/fr/terms`, a switcher and a
suggestion banner let visitors pick their language, and [dates](#dates) print in it as a browser writes them.

## Install

```bash
composer require ismailnakkar/laravel-localization
php artisan vendor:publish --tag=localization-config
```

To keep each member's language in their account, add `users.locale` and set `'user_locale' => 'locale'`:

```bash
php artisan vendor:publish --tag=localization-migrations
php artisan migrate
```

## Configuration

| Key | Default | |
|---|---|---|
| `locales` | `[]` | Language codes, default first. Fewer than two turns everything but `numbers` and [dates](#dates) off. |
| `user_locale` | `null` | The users table's language column. `null`: session only. |
| `numbers` | `null` | An ICU numbering system for Laravel's `Number` and [`IntlDate`](#dates), e.g. `'latn'`. `null`: `Number` untouched, dates in the locale's own digits. |
| `date_locales` | `[]` | App locale => the ICU locale [`IntlDate`](#dates) prints in, e.g. `['en' => 'en-GB']`. Unlisted: the app locale. |
| `legacy_query` | `null` | The query parameter older URLs carried the language in, e.g. `'lang'`: see [Older `?lang=` URLs](#older-lang-urls). `null`: off. |

Codes are ISO 639-1 plus an optional script and region, cased exactly (`en`, `en-GB`, `zh-Hant`). `es-419` and `fil`
are refused, as Google ignores them in hreflang: use `es`, `tl`. A code is also the URL segment and the app locale, so
name translation folders after it (`lang/pt-BR/`, not `pt_BR`).

With `'numbers' => 'latn'`, `Number` formats in the page language with Latin digits: `Number::format(1234567)` is
`1,234,567` on `/ar/…`, not `١٬٢٣٤٬٥٦٧`. A call's own `locale:` still wins.

## Localized routes

```php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

Each route gets a copy per language: `/terms` in the default, `/fr/terms` in French. Render
`<html lang="{{ app()->getLocale() }}">` and add [the switcher](#switcher).

- Link with `route()` or `action()`, which follow `app()->getLocale()`. `url()` and hard-coded paths lead to the
  default language.
- On `/fr/terms`, `Route::currentRouteName()` and `routeIs()` see `terms`; `route:list` shows `localization.fr.terms`.
- With `en` the default, `/en/…` 301s to the bare URL, for localized GET pages only. Register your own `/en/…` routes
  before `Route::localized()`.
- Put only pages translated into every language inside, with their forms' POST routes.
- Call `Route::localized()` outside prefix groups, before catch-all and fallback routes. Inside, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`. Either prefix mistake throws.
- An unmatched URL's 404 renders in `config('app.locale')` unless a `Route::fallback()` catches it.
- On Laravel 13, a POST catch-all inside `Route::domain()` shadows the switcher's `POST /locale`.

## The visitor's language

A copy renders its URL's language. Other pages in `web` render the visitor's: their account's, else the one they chose
(switcher or [suggestion](#suggestion)), else `Accept-Language`'s, else the default.

- Only a choice is kept: in the session, in a `localization` cookie for a year, and with `user_locale` in the account.
  Opening a copy records nothing, beyond filling an empty account once.
- Arriving from outside (another site, a bookmark, a typed URL) on a default copy 302s to the account's language, else
  the chosen one, never `Accept-Language`'s. Typed `/en/…` URLs, crawlers, signed links and clicks inside the site are
  exempt. Don't let a CDN cache localized pages' HTML.

### Switcher

It posts to `localization.switch`, which saves the language and returns to the same page in it. A signed page is
re-signed only if its route is named and its signature is valid under the current `app.key`, else it comes back
unchanged. Label each language in itself (`'fr' => 'Français'` in `lang/fr/languages.php`):

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

### Suggestion

Anyone whose language differs from the page's gets one, except on signed pages and for crawlers. Render it in your
layout, not in error views. Either answer is kept like a switcher choice, so the banner goes:

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

Don't render it while impersonating: your saver skips the save then (see [Account language](#account-language)), so
the banner would never go.

## Account language

With `user_locale` set:

- An account without a language (a value outside `locales` counts as none) takes the one on screen once: at a sign-in
  on a non-default copy (`/fr/login`, Laravel's `Login` event), else on its next page view.
- Signing out (`Logout` or `CurrentDeviceLogout`) copies it into the `localization` cookie.
- Models without the column, such as another guard's, are skipped.
- Implement `HasLocalePreference` returning the column, so mail uses it.
- Run `php artisan localization:check` on deploy: it fails (exit 1) when the default guard's users table lacks the
  column, and warns when it can't check.

The package saves the column on a fresh copy of the model, so model events fire. To save it your own way, call this in
a service provider's `boot(Localization $localization)`. The closure gets the signed-in model of any guard whose row
has the column (type-hint accordingly) and must return early while impersonating:

```php
$localization->saveUserLocaleUsing(fn (User $user, string $code) => app(Users::class)->setLocale($user, $code));
```

## Dates

`Localization\IntlDate::format(CarbonInterface $date, string $skeleton): string` prints a date as a browser's
`Intl.DateTimeFormat` writes the same fields in the same locale: ICU's best pattern for an ICU skeleton, in
`IntlDate::locale()` and the date's own timezone. The skeleton names the fields; the locale picks their order, forms,
punctuation and, for a `j` hour, its own clock. An abbreviated zone (a Carbon parsed from `…Z`, `CEST`) prints at its
offset. It throws `UnexpectedValueException` when ICU can't format.

`IntlDate::locale()` is the BCP 47 tag dates print in: the app locale, read on every call (a mail's `withLocale()`
counts), mapped through `date_locales`, hyphenated (Laravel's `pt_BR` is `pt-BR`) and with `numbers` as a `-u-nu-`
extension. A mapped tag with its own `-u-` extension is used as written. Hand it to your scripts' `Intl.DateTimeFormat`
(a date picker's `locale`) so their dates read as the server's:

```php
// config/localization.php: en is en-GB-u-nu-latn, fr is fr-u-nu-latn, ar is ar-u-nu-latn.
'numbers' => 'latn',
'date_locales' => ['en' => 'en-GB'],
```

20 November 2026 at 14:05:09 through each constant, `ar` with `'numbers' => 'latn'`:

| `IntlDate::` | Skeleton | `Intl.DateTimeFormat` options | `en-GB` | `fr` | `es` | `ar` |
|---|---|---|---|---|---|---|
| `DAY_AND_MONTH` | `dMMM` | `{day: 'numeric', month: 'short'}` | 20 Nov | 20 nov. | 20 nov | 20 نوفمبر |
| `DAY_MONTH_AND_YEAR` | `dMMMy` | `DAY_AND_MONTH`'s and `{year: 'numeric'}` | 20 Nov 2026 | 20 nov. 2026 | 20 nov 2026 | 20 نوفمبر 2026 |
| `MONTH_AND_YEAR` | `yMMMM` | `{month: 'long', year: 'numeric'}` | November 2026 | novembre 2026 | noviembre de 2026 | نوفمبر 2026 |
| `DATE_AND_TIME` | `dMMMyjm` | `DAY_MONTH_AND_YEAR`'s and `{hour: 'numeric', minute: 'numeric'}` | 20 Nov 2026, 14:05 | 20 nov. 2026, 14:05 | 20 nov 2026, 14:05 | 20 نوفمبر 2026، 2:05 م |
| `DATE_AND_TIME_WITH_SECONDS` | `dMMMyjms` | `DATE_AND_TIME`'s and `{second: 'numeric'}` | 20 Nov 2026, 14:05:09 | 20 nov. 2026, 14:05:09 | 20 nov 2026, 14:05:09 | 20 نوفمبر 2026، 2:05:09 م |
| `TIME` | `jm` | `{hour: 'numeric', minute: 'numeric'}` | 14:05 | 14:05 | 14:05 | 2:05 م |

Unmapped, `en` prints US English: "Nov 20, 2026, 2:05 PM". Any other skeleton works too: `'EEEEdMMMM'` is "Friday 20
November" in `en-GB`.

The server's ICU and the browser's may be releases apart. ICU 74 (Ubuntu 24.04) and Chrome 157 differ on `en`'s space
before PM (narrow no-break against plain), an `en-GB` weekday ("Fri, 20 Nov" against "Fri 20 Nov") and plain `ar`'s
digits (Arabic against Latin; `-u-nu-latn` prints Latin on both).

Other packages print dates through their own hooks. Point them here in a service provider's `boot()`, so every date
reads alike:

```php
use Carbon\CarbonInterface;
use Localization\IntlDate;
use Statistics\Statistics;
use Subscriptions\Subscriptions;
use Withdrawals\Withdrawals;

Statistics::dateLocaleUsing(static fn (): string => IntlDate::locale());
Subscriptions::formatDatesUsing(static fn (CarbonInterface $date): string => IntlDate::format($date, IntlDate::DAY_MONTH_AND_YEAR));
Withdrawals::formatDatesUsing(static fn (CarbonInterface $at): string => IntlDate::format($at, IntlDate::DATE_AND_TIME));
```

## Older `?lang=` URLs

With `'legacy_query' => 'lang'`, a GET or HEAD of a localized page carrying `?lang=fr` 301s to its `fr` copy, `lang`
dropped and every other parameter kept as spelt: `/terms?lang=fr&page=2` → `/fr/terms?page=2`. The value matches like
`Accept-Language`, in any case, with `-` or `_`: `fr-FR` and `fr_FR` reach `fr` when only `fr` is configured, `zh_TW`
reaches `zh-Hant`. An unknown value, a signed URL (its query is its signature), a page outside `Route::localized()` and
any other method pass through.

## A page's path in each language

`Localization\LocalizedRoute::of($route)` is `null` outside `Route::localized()`, and so is
`LocalizedRoute::copies($route, $path)`. Inside, `copies()` gives the path a copy matched in that copy and in every
language, in config order. On the `fr` copy, `copies($route, '/fr/terms')` is
`['path' => '/fr/terms', 'alternates' => ['en' => '/terms', 'fr' => '/fr/terms']]`.

With laravel-seo, add `$seo->alternatesUsing(\Localization\LocalizedRoute::copies(...))` to a service provider's
`boot(\Seo\Seo $seo)`, or the copies get no hreflang links and the sitemap lists only the paths you give it.

## Middleware

The package adds three middleware to your `web` group, which must exist:

- `RedirectLegacyQuery` first, ahead of the session and any redirect. It passes through unless `legacy_query` is set.
- `ResolveLocale` right after `StartSession`, so CSRF and throttle errors are translated.
- `ApplyLocale` right after `\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions`, as it reads the user.

`ApplyLocale` must run after your session check:

- Session check not in your priority list by name or via `AuthenticatesSessions` (Sanctum's lacks it)? List it:
  `$middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, YourSessionCheck::class)`.
- Ranked elsewhere? `$middleware->appendToPriorityList(YourSessionCheck::class, \Localization\Http\ApplyLocale::class)`.
- Livewire: add `\Localization\Http\ResolveLocale::class` and `\Localization\Http\ApplyLocale::class` to
  `Livewire::addPersistentMiddleware()`.

## API in brief

| | |
|---|---|
| `Route::localized(Closure $routes)` | Registers the routes once per language. |
| `Localization` | Inject it, as in the Blade and `boot()` examples above. |
| `$localization->languages()` | Every configured `Language`, in config order; `[]` below two. |
| `$localization->suggestion()` | The visitor's `Language` while the page is in another, else `null`. |
| `$localization->saveUserLocaleUsing(Closure)` | Saves the account's language your own way. |
| `Language` | `code`, and `current`: whether it is the page's language (always `false` for a suggestion). |
| `LocalizedRoute::of()`, `::copies()` | [A page's path in each language](#a-pages-path-in-each-language). |
| `IntlDate::format()`, `::locale()`, constants | [Dates](#dates). |
| `POST /locale` (`localization.switch`) | The switcher: `locale`, and `to`, the page to return to. |
| `ResolveLocale::PICKED_KEY`, `ResolveLocale::COOKIE` | The session key and cookie holding the choice. |
| `RedirectLegacyQuery`, `ResolveLocale`, `ApplyLocale` | The [middleware](#middleware), in `Localization\Http`. |
| `php artisan localization:check` | The deploy check for `user_locale`. |
| `localization-config`, `localization-migrations` | Publish tags. |

## Testing

Nothing to fake: request `/fr/terms` like any URL. Routes read `locales` when they register, so set it before the app
boots. Laravel's test requests send `Accept-Language: en-us,en;q=0.5`; set the header to test another browser
language. A visitor who chose a language is `withSession([ResolveLocale::PICKED_KEY => 'fr'])` or
`withCookie(ResolveLocale::COOKIE, 'fr')`.

## Upgrading

### From 0.10

`LocalizedRoute->path($path, $code)` is `@internal`: read `LocalizedRoute::copies($route, $path)['alternates'][$code]`
instead.

### From 0.9

- PHP's `intl` extension is required (`IntlDate`; `numbers` already needed it).
- Nothing else to change: an older published config lacks `date_locales`, which maps nothing.

### From 0.8

Nothing to change: an older published config lacks `legacy_query`, which stays off. An app with its own `?lang=`
redirect sets `'legacy_query' => 'lang'` and deletes it.

### From 0.7

- `Localization::accountLanguage()` is removed: read your user's language column, or its `preferredLocale()`, instead.
- `localization-migrations` publishes `add_locale_to_users_table` (`users.locale`). Skip it if `user_locale` already
  names a column of yours.

[MIT licensed](LICENSE).
