# Laravel Localization

Localized routes and per-visitor language for Laravel.

- `Route::localized()` gives each page one copy per language (`/terms`, `/fr/terms`; `/en/terms` redirects to `/terms`
  when `en` is the default), and `route()` links to the current language's copy.
- Each visitor's language is remembered in the session and, optionally, on their account, with a switcher to change it.
- With [laravel-seo](https://github.com/ismailnakkar/laravel-seo) 0.5+, every copy gets canonical, hreflang and sitemap
  entries automatically.

Requires PHP 8.4+ and Laravel 12.61.1+ or 13.12+. Coming from laravel-seo 0.4? Follow its
[UPGRADE.md](https://github.com/ismailnakkar/laravel-seo/blob/main/UPGRADE.md#from-04-to-05).

## Installation

```bash
composer require ismailnakkar/laravel-localization
php artisan vendor:publish --tag=localization-config
```

## Usage

List your languages, default first:

```php
// config/localization.php
'locales' => ['en', 'fr', 'es'],
```

Wrap the translated pages in `Route::localized()` and link to them with `route()`:

```php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

Then render `<html lang="{{ app()->getLocale() }}">`, add [the switcher](#switcher), and run
`php artisan localization:check` on deploy.

### Localized routes

- Call `Route::localized()` outside any prefix group, before catch-all and fallback routes. Inside it, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`.
- Only put pages translated into every language (and their forms' POST routes) inside: every copy is listed in
  hreflang and the sitemap.
- Link with `route()`: `url()` and hard-coded paths lead to the default language.
- On `/fr/terms`, `Route::currentRouteName()` and `routeIs()` see `terms`; `route:list` shows `localization.fr.terms`.
- `/en/…` redirects (301) only for localized GET pages; other `/en/…` paths fall through to your fallback, else 404.
  Register your own `/en/…` routes before `Route::localized()`.
- A URL no route matches renders its 404 in `config('app.locale')` unless a `Route::fallback()` catches it.

### How the language is chosen

A localized page renders its URL's language. Other pages in `web` use the session's language, else the account's, else
the browser's (`Accept-Language`), else the default.

Visiting a copy in another language (a page load, Inertia visit, `wire:navigate`, or a link from another site) switches
the session to it. Signed links and Livewire updates don't, nor, in browsers that send Fetch Metadata, `<img>` and
iframes. Prefetching counts as a visit: exclude
links to other languages from it (e.g. `data-turbo-prefetch="false"`).

`entry_redirect` (e.g. `['home']`) lists pages whose default copy redirects (302) visitors arriving from outside the
site (another site, a bookmark, a typed URL) to their language's copy. Crawlers, signed links and internal clicks
aren't redirected. Don't let a CDN cache these pages' HTML.

## Switcher

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

It saves the language to the session (and, with `user_locale` set, to the account) and returns the visitor to the same
page in that language. A signed page is re-signed only if its route is named and its signature is valid under the
current `app.key`; otherwise it comes back unchanged. Each label is `languages.{code}` in its own language, e.g.
`'fr' => 'Français'` in `lang/fr/languages.php`.

On Laravel 13, a POST catch-all inside `Route::domain()` shadows `POST /locale`.

## Account language

Set `user_locale` to your users table's language column. The switcher saves to it, and an account without a language
gets the visitor's once (a value outside `locales` counts as none and is overwritten). Models without the column
(e.g. another guard's admin) are skipped. Implement `HasLocalePreference` on your User, returning that column, so mail
goes out in the account's language.

The session wins over the account, so they can differ. To offer syncing them, render this in your layout (not in error
views):

```blade
@inject('localization', \Localization\Localization::class)
@if ($offer = $localization->accountLanguageOffer())
    <dialog id="account-language">
        <form method="POST" action="{{ route('localization.switch') }}">
            @csrf
            <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
            <p>{{ __('Use this language for your account too?') }}</p>
            <button name="locale" value="{{ $localization->accountLanguage() }}" class="keep">{{ __('No, keep mine') }}</button>
            <button name="locale" value="{{ $offer->code }}">{{ __('Yes') }}</button>
        </form>
    </dialog>
    <script type="module">
        const dialog = document.getElementById('account-language');
        // Escape closes it without a button, and Chrome may skip the cancel event: it counts as keeping.
        dialog.addEventListener('close', () => dialog.querySelector('form').requestSubmit(dialog.querySelector('.keep')));
        dialog.showModal();
    </script>
@endif
```

The package saves the column with a normal model save, so model events fire. To save it your own way, register a
closure in a service provider's `boot(Localization $localization)`:

```php
$localization->saveUserLocaleUsing(fn (User $user, string $code) => app(Users::class)->setLocale($user, $code));
```

It receives the signed-in model of any guard whose row has the column, so type-hint accordingly. Impersonating signs
you in as the member, so the switcher and the one-time fill write their account: return early while impersonating.

## Middleware

With two or more `locales` and `remember_locale` on, two middleware join the `web` group: `ResolveLocale`, ranked right
after `StartSession` so CSRF and throttle errors are translated, and `ApplyLocale`, ranked right after
`\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions` because it reads the user.

- `ApplyLocale` must run after your session check. If that check isn't in your priority list, by name or through
  `AuthenticatesSessions` (Sanctum's lacks the contract), list it:
  `$middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, YourSessionCheck::class)`.
- If you rank your session check elsewhere, rank `ApplyLocale` right after it:
  `$middleware->appendToPriorityList(YourSessionCheck::class, \Localization\Http\ApplyLocale::class)`.
- Livewire: add `\Localization\Http\ResolveLocale::class` and `\Localization\Http\ApplyLocale::class` to
  `Livewire::addPersistentMiddleware()`.

## Your own locale logic

With `'remember_locale' => false`, only a copy's URL sets the language: no session, account, switcher or entry
redirect. `route()`, `languages()` and laravel-seo still work. Pick the language (`$code`) in your own middleware, and
add it to `Livewire::addPersistentMiddleware()` if you use Livewire:

```php
app()->setLocale(\Localization\LocalizedRoute::of($request->route())->locale ?? $code);
```

To redirect old `?lang=fr` URLs, check `$code` is one of your locales, then redirect to
`LocalizedRoute::of($request->route())?->path($request->getPathInfo(), $code)`.

## localization:check

It exits 1 on any FAIL, and checks that:

- `leftover keys`: no laravel-seo 0.4 language keys remain in `config/seo.php`.
- `entry_redirect`: every name is a `Route::localized()` route.
- `user_locale`: the default guard's users table has the column (WARN when it can't be checked).

## Configuration

| Key | Default | Description |
|---|---|---|
| `locales` | `[]` | Language codes, default first. Fewer than two turns everything off. |
| `remember_locale` | `true` | `false`: only a copy's URL sets the language. |
| `user_locale` | `null` | The users table's language column. `null`: session only. |
| `entry_redirect` | `[]` | Route names whose default copy redirects arrivals from outside to their language. |

Codes must be ISO 639-1 with an optional script and region, cased exactly so (`en`, `en-GB`, `zh-Hant`): the only form
Google reads in hreflang (`es`, not `es-419`; `tl`, not `fil`). Each code is also the URL segment and app locale, so
name translation folders after it (`lang/pt-BR/`, not `pt_BR`).

## License

MIT. See [LICENSE](LICENSE).
