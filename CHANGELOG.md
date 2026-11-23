# Changelog

All notable changes are listed here, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). While 0.x, breaking changes bump the minor. Anything
marked `@internal` may change in any release.

## [0.14.0] - 2026-11-23

### Added

- `Localization\DateForm`: the forms `IntlDate` prints (`day`, `monthAndYear`, `dayAndTime`, `dayTimeAndZone`,
  `time`). `dayTimeAndZone` is the old `DATE_AND_TIME . ZONE`.

### Changed

- `IntlDate::format()` takes a `DateForm` in place of a skeleton string. See README Upgrading, From 0.13.

### Removed

- `IntlDate::day()`, `dayAndTime()` and `time()`, and the constants `DAY_MONTH_AND_YEAR`, `MONTH_AND_YEAR`,
  `DATE_AND_TIME`, `TIME` and `ZONE`: print `IntlDate::format($at, DateForm::…)`.
- `IntlDate::DAY_AND_MONTH`, `ZONE` added to another form, and skeleton strings: no form prints them. See README
  Upgrading, From 0.13.

## [0.13.0] - 2026-11-23

### Added

- `Localization::readOnlyWhen(Closure $readOnly)`: while `$readOnly(Request $request)` returns `true` (an admin viewing
  as a member), the account's language is never saved, through a fill, a sign-in or the switcher, and `suggestion()` is
  `null`. Without it nothing is read-only. Nothing to change.
- `Language::dir()`: `rtl` for a right-to-left language (`ar`, `he`, `pa-Arab`), else `ltr`, for the suggestion's
  `dir` attribute.

### Changed

- Requires `symfony/polyfill-php85` `^1.34` (`locale_is_right_to_left()` on PHP 8.4), which both supported Laravel
  floors already install.

## [0.12.0] - 2026-11-22

### Added

- `IntlDate::day()`, `dayAndTime()` and `time()`: the `DAY_MONTH_AND_YEAR`, `DATE_AND_TIME` and `TIME` forms by name.
  No date (`null`) prints as `null`, so a page words its own fallback (`?? '—'`).
- `IntlDate::format()` takes `null` too, and returns it.
- `IntlDate::ZONE` (`{timeZoneName: 'short'}`), added to a skeleton, names the zone where the language puts it:
  `DATE_AND_TIME.ZONE` is "20 Nov 2026, 14:05 UTC" in `en-GB`. Nothing to change.

### Removed

- `IntlDate::DATE_AND_TIME_WITH_SECONDS`: dates print to the minute. Print `DATE_AND_TIME`, or `dayAndTime()`, instead.

## [0.11.0] - 2026-11-21

### Changed

- `LocalizedRoute->path()` is `@internal` and leaves the README: read
  `LocalizedRoute::copies($route, $path)['alternates'][$code]` instead. `LocalizedRoute->locales`, typed with the
  internal `Locales`, is `@internal` too.

## [0.10.0] - 2026-11-20

### Added

- `Localization\IntlDate`: `format($date, $skeleton)` prints a date as a browser's `Intl.DateTimeFormat` writes the
  same fields, in `IntlDate::locale()` and the date's own timezone (an abbreviated zone such as `Z` at its offset), with
  constants for a day, a date, a month, a date and time (with seconds) and a time, their hours on the locale's own
  clock. `IntlDate::locale()` is the BCP 47 tag for scripts: the app locale mapped
  through `date_locales` (default `[]`, the app locale itself), with `numbers` as its `-u-nu-` extension.

### Changed

- Requires `ext-intl`.

## [0.9.0] - 2026-11-09

### Added

- `legacy_query` (default `null`, off): set to a query parameter (`'lang'`), a GET or HEAD of a localized page carrying
  `?lang=fr` 301s to its `fr` copy, `lang` dropped and every other parameter kept as spelt. The value matches like
  `Accept-Language` (`fr-FR` and `fr_FR` reach `fr`, `zh_TW` reaches `zh-Hant`); an unknown value, a signed URL, a page
  outside `Route::localized()` and any other method pass through. Its middleware, `RedirectLegacyQuery`, heads `web`,
  ahead of the session and any redirect. Nothing to change.

## [0.8.0] - 2026-10-19

### Added

- `add_locale_to_users_table`, published with `localization-migrations`: a nullable `users.locale` (`string(10)`) for
  `user_locale`. Publish only: an installed app runs nothing new.

### Removed

- `Localization::accountLanguage()`: read your user's language column, or its `preferredLocale()`, instead.
- The upgrade message for laravel-seo 0.2's `Route::localized($locales, $routes)` call: its parameter is a
  `Closure`, so a wrong call gets PHP's `TypeError`.

## [0.7.1] - 2026-10-18

### Fixed

- The `RouteMatched` listener that applies a copy's language is a class callable (`@internal` `MatchedRoute::apply`),
  not a closure, so `event:list` names it. Nothing to change.

## [0.7.0] - 2026-10-15

### Added

- `numbers` (default `null`, off): Laravel's `Number` formats in the page language with that ICU numbering system
  (`'latn'`: Latin digits in Arabic), from boot and on every `setLocale()`, a notification's `withLocale()` too.

## [0.6.2] - 2026-10-15

### Fixed

- Only a sign-in on a non-default copy (`/fr/login`) fills an empty account. Elsewhere the sign-in saved the default
  language (on the bare copy), or the browser's or the app's default over the visitor's choice (a cross-site POST
  such as an identity provider's form_post, a route outside `web`, a job or the console). The page it lands on
  fills those again, as before 0.6.1.

## [0.6.1] - 2026-10-15

### Fixed

- Signing in (Laravel's `Login` event, a sign-up's too) fills an empty account with the
  language on screen, as the README promises. The unprefixed page a sign-in lands on used to fill it with the
  browser's language instead. A remember-me restore fills nothing.

## [0.6.0] - 2026-10-15

### Added

- Signing out (Laravel's `Logout` or `CurrentDeviceLogout` event) keeps the account's language in the `localization`
  cookie, so the guest's next visit opens in it.

### Changed

- Booting the HTTP kernel throws when the app has no `web` middleware group, instead of silently skipping
  `ResolveLocale` and `ApplyLocale`.

## [0.5.3] - 2026-10-14

### Fixed

- `ResolveLocale` and `ApplyLocale` join `web` on every boot and read `locales` per request, so languages set after
  boot (a test's `config()`) apply; with fewer than two they pass through.

## [0.5.2] - 2026-10-14

### Added

- README: the Upgrading steps 0.2.0, 0.3.0 and 0.5.0 left out, under `### From X.Y` headings, and the laravel-seo
  `alternatesUsing()` line under "Building a language's path".

## [0.5.1] - 2026-10-06

0.5.0's upgrade line is withdrawn: nothing picks the copies up by itself. An app passes `LocalizedRoute::copies(...)`
to its SEO package's alternates hook with one line.

### Removed

- The Composer `conflict` added in 0.5.0: no other package's version is refused.

## [0.5.0] - 2026-10-06

With laravel-seo installed, update it to 0.6 in the same `composer update`: it now reads the copies itself.

### Added

- `LocalizedRoute::copies($route, $path)`: every language's path for one a localized route matched,
  `['path' => …, 'alternates' => [code => path]]` in config order; `null` outside `Route::localized()`.

### Changed

- Installed beside laravel-seo, Composer refuses laravel-seo before 0.6, which would silently lose the copies' hreflang
  and sitemap entries.

### Removed

- The laravel-seo bridge (`SeoAlternates`, `@internal`): laravel-seo 0.6 picks the copies up itself. laravel-seo is no
  longer suggested or needed by the tests.

## [0.4.0] - 2026-10-03

### Changed

- `localization:check` only checks `user_locale` now: the warnings for `seo.locales`, `seo.user_locale`,
  `entry_redirect` and `remember_locale` are gone, and `Route::localized()` no longer reads `config('seo.locales')`.
- `LocalizationServiceProvider` is `final`; one crawler detector is shared instead of one built per call.

### Added

- README: Requirements, Install, Testing and Upgrading sections, and `LocalizedRoute::of()` / `path()` documented.

## [0.3.1] - 2026-09-28

### Added

- A choice is also kept in a `localization` cookie for a year (`ResolveLocale::COOKIE`), so a guest keeps it past the
  session. Only `localization.switch` sets it; the session's choice is read first.

## [0.3.0] - 2026-09-28

To upgrade, make the [suggestion banner](https://github.com/ismailnakkar/laravel-localization#suggestion)'s
second button post the page's language as "Use <language>", remove `remember_locale` from `config/localization.php`,
and drop references to `ResolveLocale::SESSION_KEY`.
Members now see the banner too: don't render it while impersonating, where no answer is saved.

### Changed

- `Localization::suggestion()` offers the visitor's language (the account's, else their choice, else the browser's) to
  members and guests whenever the page shows another, and either answer is kept.
- Opening a copy no longer records a language: pages without one in the URL use the account's, the choice, else the
  browser's.
- The suggestion is never shown on a signed page.

### Fixed

- Typing `/en/…` no longer fills an empty account with the browser's language: the English page it lands on fills it.

### Removed

- `ResolveLocale::SESSION_KEY` (`localization.browsing`).
- The `remember_locale` config key: with two or more locales, the session, account, switcher, entry redirect and
  suggestion are always on. `localization:check` warns while it is set.

## [0.2.0] - 2026-09-28

To upgrade, remove `entry_redirect` from `config/localization.php` and replace the account-language modal with the
[suggestion banner](https://github.com/ismailnakkar/laravel-localization#suggestion).

### Added

- `Localization::suggestion()`: the browser's language, to offer a visitor who told us none.
- The `localization.picked` session key (`ResolveLocale::PICKED_KEY`), set by `localization.switch` and from a signed-in
  member's account.

### Changed

- A member's account language outranks the session on pages without a language in the URL.
- The entry redirect covers the default copy of every localized page, and only for a known language (the account's or
  a pick), never `Accept-Language`'s.
- A default copy opened from outside the site (a bookmark, a typed URL, another site) records no language.

### Removed

- `Localization::accountLanguageOffer()`: render the suggestion banner instead.
- The `entry_redirect` config key; `localization:check` warns while it is set.

## [0.1.0] - 2026-09-28

Extracted from laravel-seo 0.4.2 under new names. To upgrade, follow its
[UPGRADE.md](https://github.com/ismailnakkar/laravel-seo/blob/main/UPGRADE.md#from-04-to-05).

### Added

- `localization:check` fails while laravel-seo 0.4's language keys remain in `config/seo.php`, and
  `Route::localized()` throws while only `seo.locales` lists languages.
- Canonicals, hreflang and sitemap alternates for localized routes with laravel-seo 0.5.

### Fixed

- The switcher no longer doubles the base path in apps served from a subdirectory (`/app/app/terms`).

[0.14.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.14.0
[0.13.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.13.0
[0.12.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.12.0
[0.11.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.11.0
[0.10.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.10.0
[0.9.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.9.0
[0.8.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.8.0
[0.7.1]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.7.1
[0.7.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.7.0
[0.6.2]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.6.2
[0.6.1]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.6.1
[0.6.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.6.0
[0.5.3]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.5.3
[0.5.2]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.5.2
[0.5.1]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.5.1
[0.5.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.5.0
[0.4.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.4.0
[0.3.1]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.3.1
[0.3.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.3.0
[0.2.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.2.0
[0.1.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.1.0
