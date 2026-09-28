# Changelog

All notable changes are listed here, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). While 0.x, breaking changes bump the minor. Anything
marked `@internal` may change in any release.

## [0.1.0] - 2026-09-28

Extracted from laravel-seo 0.4.2 under new names. To upgrade, follow its
[UPGRADE.md](https://github.com/ismailnakkar/laravel-seo/blob/main/UPGRADE.md#from-04-to-05).

### Added

- `localization:check` fails while laravel-seo 0.4's language keys remain in `config/seo.php`, and
  `Route::localized()` throws while only `seo.locales` lists languages.
- Canonicals, hreflang and sitemap alternates for localized routes with laravel-seo 0.5.

### Fixed

- The switcher no longer doubles the base path in apps served from a subdirectory (`/app/app/terms`).

[0.1.0]: https://github.com/ismailnakkar/laravel-localization/releases/tag/v0.1.0
