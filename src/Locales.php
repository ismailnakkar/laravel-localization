<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Http\Request;
use InvalidArgumentException;

/** @internal */
final readonly class Locales
{
    /** ISO 639-1, then an optional script and region, cased as hreflang writes them: en, en-GB, zh-Hant. */
    private const string HREFLANG_CODE = '/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/D';

    /**
     * @param  list<string>  $codes
     * @param  string  $default  not config('app.locale'): setLocale() overwrites it
     *
     * @throws InvalidArgumentException
     */
    public function __construct(public array $codes, public string $default)
    {
        foreach ($codes as $i => $code) {
            if (! is_string($code) || preg_match(self::HREFLANG_CODE, $code) !== 1) {
                throw new InvalidArgumentException('localization.locales: [' . (is_string($code) ? $code : get_debug_type($code)) . '] is not an hreflang code like en, en-GB or zh-Hant.');
            }

            if (array_search($code, $codes, true) !== $i) {
                throw new InvalidArgumentException("localization.locales: [{$code}] is listed twice.");
            }
        }

        if (! $this->has($default)) {
            throw new InvalidArgumentException("localization.locales: the default [{$default}] is not one of the codes.");
        }
    }

    /** @throws InvalidArgumentException */
    public static function configured(): ?self
    {
        $locales = config('localization.locales');

        if (blank($locales) || $locales === false) {
            return null;
        }

        // Not array_is_list(): array_filter() leaves gaps.
        if (! is_array($locales) || ! array_all($locales, static fn (mixed $code, int|string $key): bool => is_int($key) && is_string($code))) {
            throw new InvalidArgumentException("localization.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations.");
        }

        $codes = array_values($locales);

        return count($codes) < 2 ? null : new self($codes, $codes[0]);
    }

    /** @phpstan-assert-if-true =string $code */
    public function has(mixed $code): bool
    {
        return is_string($code) && in_array($code, $this->codes, true);
    }

    /** Not Symfony's getPreferredLanguage(): it returns en_GB, not en-GB. */
    public function preferredBy(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if (($code = $this->codeFor($language)) !== null) {
                return $code;
            }
        }

        return null;
    }

    /** A language tag's code (any case, `_` or `-`): exact, else its language's (fr-CA → fr, zh-TW → zh-Hant). */
    public function codeFor(string $language): ?string
    {
        $codes = array_combine(array_map(strtolower(...), $this->codes), $this->codes);
        $language = strtolower(str_replace('_', '-', $language));
        $primary = explode('-', $language)[0];

        return $codes[$language]
            ?? $codes[$primary]
            ?? array_find($codes, static fn (string $code, string $lower): bool => str_starts_with($lower, "{$primary}-"));
    }
}
