<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The languages Route::localized() serves; the default gets the bare URL and x-default.
 *
 * @internal
 */
final readonly class Locales
{
    /**
     * @param  list<string>  $codes  hreflang codes, also URL segments and app locales (zh-Hant); shape-checked only
     * @param  string  $default  never config('app.locale'), which Application::setLocale() overwrites
     *
     * @throws InvalidArgumentException a malformed or duplicate code, or a default not in $codes
     */
    public function __construct(public array $codes, public string $default)
    {
        foreach ($codes as $i => $code) {
            if (! is_string($code) || preg_match('/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/D', $code) !== 1) {
                throw new InvalidArgumentException('localization.locales: [' . (is_string($code) ? $code : get_debug_type($code)) . '] is not an hreflang code like en, en-GB or zh-Hant.');
            }

            if (array_search($code, $codes, true) !== $i) {
                throw new InvalidArgumentException("localization.locales: [{$code}] is listed twice.");
            }
        }

        if (! in_array($default, $codes, true)) {
            throw new InvalidArgumentException("localization.locales: the default [{$default}] is not one of the codes.");
        }
    }

    /**
     * config('localization.locales'), first code the default; null below two codes (language features off).
     *
     * @throws InvalidArgumentException not a list of hreflang codes; a duplicate code
     */
    public static function configured(): ?self
    {
        $locales = config('localization.locales');

        if (blank($locales) || $locales === false) {
            return null;
        }

        // Integer keys may have gaps (array_filter()); a code => name map is refused, not guessed at.
        if (! is_array($locales) || ! array_all($locales, static fn (mixed $code, int|string $key): bool => is_int($key) && is_string($code))) {
            throw new InvalidArgumentException("localization.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations.");
        }

        $codes = array_values($locales);

        return count($codes) < 2 ? null : new self($codes, $codes[0]);
    }

    /**
     * The first browser language matching a code exactly (any case, `_` as `-`), else by primary language, bare code
     * first (fr-CA → fr, pt over pt-BR); null if none. Symfony's getPreferredLanguage() returns en_GB, not en-GB.
     */
    public function preferredBy(Request $request): ?string
    {
        $codes = array_combine(array_map(strtolower(...), $this->codes), $this->codes);

        foreach ($request->getLanguages() as $language) {
            $language = strtolower(str_replace('_', '-', $language));
            $primary = explode('-', $language)[0];

            $match = $codes[$language]
                ?? $codes[$primary]
                ?? array_find($codes, static fn (string $code, string $lower): bool => str_starts_with($lower, "{$primary}-"));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
