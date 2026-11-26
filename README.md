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
- A path no route matches, or only `Route::fallback()` does, renders in its first segment's language when that is a
  code other than the default: `/fr/missing` 404s in French, whether your `errors/404` view, your fallback or your
  exception handler renders it. Any other (`/missing`, `/en/missing`, `/xx/missing`) renders in `config('app.locale')`,
  or in the visitor's language when a fallback in `web` catches it.
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
unchanged. `$language->name()` names each language in itself, as a menu lists it:

```blade
@inject('localization', \Localization\Localization::class)
<form method="POST" action="{{ route('localization.switch') }}">
    @csrf
    <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
    @foreach ($localization->languages() as $language)
        <button name="locale" value="{{ $language->code }}" lang="{{ $language->code }}" @if ($language->current) aria-current="true" @endif>{{ $language->name() }}</button>
    @endforeach
</form>
```

`name()` is ICU's name for the code in that language, its first letter capitalised: `English`, `Français`, `العربية`,
`Español`; a name opening with a lower-case prefix keeps it (`isiZulu`). In a sentence, word it with your own lines, as
French writes "en français".

`Language::of($code)` builds the `Language` of any code, or of a string-backed enum case, `current` when the page is in
it. Your own language enum can name itself through it:

```php
use Localization\Language;

enum Locale: string
{
    case en = 'en';
    case fr = 'fr';

    public function label(): string
    {
        return Language::of($this)->name();
    }

    public function dir(): string
    {
        return Language::of($this)->dir();
    }
}
```

### Suggestion

Anyone whose language differs from the page's gets one, except on signed pages, for crawlers and on
[read-only requests](#read-only-requests). Render it in your layout, not in error views. Either answer is kept like a
switcher choice, so the banner goes. It words the languages with your own lines (`'fr' => 'français'` in
`lang/fr/languages.php`), as they sit in a sentence:

```blade
@inject('localization', \Localization\Localization::class)
@if ($suggestion = $localization->suggestion())
    <aside lang="{{ $suggestion->code }}" dir="{{ $suggestion->dir() }}">
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

Its `dir()` is `rtl` for a right-to-left language (`ar`, `he`, a code in an RTL script such as `pa-Arab`), else `ltr`.

### Read-only requests

While an admin views the site as a member, nothing should write to an account, and an answer to the banner could not
be kept, so it would never go. Name those requests in a service provider's `boot(Localization $localization)`, here
laravel-accounts' login-as:

```php
$localization->readOnlyWhen(\Accounts\Accounts::impersonating(...));
```

While the closure, `fn (Request $request): bool`, returns `true`, the account's language is never saved (no fill, no
sign-in fill, no switcher save) and `suggestion()` is `null`. The switcher still keeps a choice in the session and the
cookie, where an account's language outranks it: hiding it too is your layout's call.

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
has the column (type-hint accordingly), and is never called while [read-only](#read-only-requests):

```php
$localization->saveUserLocaleUsing(fn (User $user, string $code) => app(Users::class)->setLocale($user, $code));
```

## Dates

`Localization\IntlDate::format(?CarbonInterface $date, DateForm $form): ?string` prints a date as a browser's
`Intl.DateTimeFormat` writes the same fields in the same locale: ICU's best pattern for the form, in
`IntlDate::locale()` and the date's own timezone. The form names the fields; the locale picks their order, forms,
punctuation and, for an hour, its own clock. An abbreviated zone (a Carbon parsed from `…Z`, `CEST`) prints at its
offset. No date (`null`) prints as `null`, so the page words its own fallback:
`IntlDate::format($at, DateForm::dayAndTime) ?? '—'`. It throws `UnexpectedValueException` when ICU can't format.

`IntlDate::locale()` is the BCP 47 tag dates print in: the app locale, read on every call (a mail's `withLocale()`
counts), mapped through `date_locales`, hyphenated (Laravel's `pt_BR` is `pt-BR`) and with `numbers` as a `-u-nu-`
extension. A mapped tag with its own `-u-` extension is used as written. Hand it to your scripts' `Intl.DateTimeFormat`
(a date picker's `locale`) so their dates read as the server's:

```php
// config/localization.php: en is en-GB-u-nu-latn, fr is fr-u-nu-latn, ar is ar-u-nu-latn.
'numbers' => 'latn',
'date_locales' => ['en' => 'en-GB'],
```

20 November 2026 at 14:05:09 UTC in each `Localization\DateForm`, `ar` with `'numbers' => 'latn'`:

| `DateForm::` | `Intl.DateTimeFormat` options | `en-GB` | `fr` | `es` | `ar` |
|---|---|---|---|---|---|
| `day` | `{day: 'numeric', month: 'short', year: 'numeric'}` | 20 Nov 2026 | 20 nov. 2026 | 20 nov 2026 | 20 نوفمبر 2026 |
| `monthAndYear` | `{month: 'long', year: 'numeric'}` | November 2026 | novembre 2026 | noviembre de 2026 | نوفمبر 2026 |
| `dayAndTime` | `day`'s and `{hour: 'numeric', minute: 'numeric'}` | 20 Nov 2026, 14:05 | 20 nov. 2026, 14:05 | 20 nov 2026, 14:05 | 20 نوفمبر 2026، 2:05 م |
| `dayTimeAndZone` | `dayAndTime`'s and `{timeZoneName: 'short'}` | 20 Nov 2026, 14:05 UTC | 20 nov. 2026, 14:05 UTC | 20 nov 2026, 14:05 UTC | 20 نوفمبر 2026، 2:05 م UTC |
| `time` | `{hour: 'numeric', minute: 'numeric'}` | 14:05 | 14:05 | 14:05 | 2:05 م |

Unmapped, `en` prints US English: "Nov 20, 2026, 2:05 PM". `dayTimeAndZone` names the zone where the language puts it;
a region prints the language's short name ("CET" in `en-GB`, "UTC+1" in `fr`).

The server's ICU and the browser's may be releases apart. ICU 74 (Ubuntu 24.04) and Chrome 157 differ on `en`'s space
before PM (narrow no-break against plain) and plain `ar`'s digits (Arabic against Latin; `-u-nu-latn` prints Latin on
both).

Other packages print dates through their own hooks. Point them here in a service provider's `boot()`, so every date
reads alike:

```php
use Carbon\CarbonInterface;
use Localization\DateForm;
use Localization\IntlDate;
use Statistics\Statistics;
use Subscriptions\Subscriptions;
use Withdrawals\Withdrawals;

Statistics::dateLocaleUsing(static fn (): string => IntlDate::locale());
Subscriptions::formatDatesUsing(static fn (CarbonInterface $date): string => IntlDate::format($date, DateForm::day));
Withdrawals::formatDatesUsing(static fn (CarbonInterface $at): string => IntlDate::format($at, DateForm::dayAndTime));
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
| `$localization->readOnlyWhen(Closure)` | [Read-only requests](#read-only-requests): no account save, no suggestion. |
| `Language` | `code`; `current`, whether it is the page's language (always `false` for a suggestion); `name()`, the language named in itself; `dir()`, `rtl` or `ltr`. |
| `Language::of($code)` | Any code's `Language`, or a string-backed enum case's. |
| `LocalizedRoute::of()`, `::copies()` | [A page's path in each language](#a-pages-path-in-each-language). |
| `IntlDate::format()`, `IntlDate::locale()`, `DateForm` | [Dates](#dates). |
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

### From 0.14

Nothing to change. New: `Language::of($code)`, any code's or a string-backed enum case's `Language`, and
`Language::name()`, the language named in itself. One behaviour changes: a path no route matches, or only
`Route::fallback()` does, now renders in its first segment's language when that is a code other than the default, in
`web` or not, where it rendered in `config('app.locale')` or the visitor's language; on a fallback in `web`, an account
without a language fills with it, as on a copy. `/en/missing`, the default's code, renders like `/missing`.

Require `"ismailnakkar/laravel-localization": "^0.15"`.

### From 0.13

`IntlDate::format()` takes a `Localization\DateForm`. The named forms, the constants and skeleton strings are removed;
each form prints as before:

| 0.13 | 0.14 |
|---|---|
| `IntlDate::day($at)`, `IntlDate::format($at, IntlDate::DAY_MONTH_AND_YEAR)` | `IntlDate::format($at, DateForm::day)` |
| `IntlDate::format($at, IntlDate::MONTH_AND_YEAR)` | `IntlDate::format($at, DateForm::monthAndYear)` |
| `IntlDate::dayAndTime($at)`, `IntlDate::format($at, IntlDate::DATE_AND_TIME)` | `IntlDate::format($at, DateForm::dayAndTime)` |
| `IntlDate::format($at, IntlDate::DATE_AND_TIME . IntlDate::ZONE)` | `IntlDate::format($at, DateForm::dayTimeAndZone)` |
| `IntlDate::time($at)`, `IntlDate::format($at, IntlDate::TIME)` | `IntlDate::format($at, DateForm::time)` |

`IntlDate::DAY_AND_MONTH`, `ZONE` added to another form, and any other skeleton have no form: a page that prints one
uses its own `IntlDateFormatter`. Import `Localization\DateForm` (`@use('Localization\DateForm')` in Blade) and require
`"ismailnakkar/laravel-localization": "^0.14"`.

### From 0.12

0.13 requires `symfony/polyfill-php85` `^1.34`, for `locale_is_right_to_left()` on PHP 8.4. Laravel 12.61.1+ and 13.12+
already install it, so an app changes nothing for it.

Nothing else breaks: without `readOnlyWhen()` nothing is read-only. An app that kept login-as read-only itself moves it
here:

- Add `$localization->readOnlyWhen(Accounts::impersonating(...));` (or your own check) to a service provider's
  `boot(Localization $localization)`. Without it, and without a saver that skips login-as, a page view during login-as
  fills the admin's own empty language.
- Drop the impersonation early return from your `saveUserLocaleUsing()` closure.
- Drop the impersonation condition around the suggestion banner, in its partial or in the layout that includes it.
- Your own enum's direction for the offered language (`YourLocale::from($suggestion->code)->dir()`) becomes
  `$suggestion->dir()`.

Hiding the switcher during login-as stays your layout's call. Require `"ismailnakkar/laravel-localization": "^0.13"`.

### From 0.11

- `IntlDate::DATE_AND_TIME_WITH_SECONDS` is removed: print `DATE_AND_TIME` (to the minute) instead, or `dayAndTime()`.
  An app's own wrapper that printed seconds becomes `dayAndTime()` too.
- `IntlDate::format()` takes `null` too, and returns it. To adopt the named forms, replace an app's own null-safe date
  wrappers with them, form by form: `day()` for `DAY_MONTH_AND_YEAR`, `dayAndTime()` for `DATE_AND_TIME`, `time()` for
  `TIME`. A zone appended as text (`. ' UTC'`) becomes `IntlDate::ZONE` added to the skeleton.

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
