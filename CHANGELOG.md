# Changelog

All notable changes to `ismailnakkar/laravel-localization` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Semver covers what the README documents; anything marked `@internal` may change in any release.

## [1.0.0] - 2026-09-28

Extracted from ismailnakkar/laravel-seo 0.4.2, with the same behaviour under new names (bar one fix, below): every
step is in its [UPGRADE.md](https://github.com/ismailnakkar/laravel-seo/blob/main/UPGRADE.md#from-04-to-05).

### Added

- `localization:check` FAILs while `config/seo.php` still sets `locales`, `user_locale`, `entry_redirect` or
  `remember_locale => false`, and `Route::localized()` throws while `seo.locales` lists two or more codes and
  `localization.locales` fewer: languages left there would otherwise make every `/fr/…` URL a silent 404.
- With laravel-seo 0.5 installed, the localized routes' canonicals, hreflang and sitemap alternates, through its
  `Seo::alternatesUsing()`.

### Fixed

- The switcher in an app served from a subdirectory: `to` carries the base path, which the redirect added again, so
  `/app/terms` landed on `/app/app/terms` instead of `/app/fr/terms`.

[1.0.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v1.0.0
