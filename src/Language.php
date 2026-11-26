<?php

declare(strict_types=1);

namespace Localization;

use BackedEnum;
use Locale;

/** A language: a Localization::languages() or suggestion() entry, or any code's through of(); $current: the page's. */
final readonly class Language
{
    /** A lower-case run, then a capital, opening the name: a prefix the name keeps lower-case (isiZulu, chiShona). */
    private const string LOWER_CASE_PREFIX = '/^\p{Ll}+\p{Lu}/u';

    public function __construct(public string $code, public bool $current) {}

    /** The language of $code, or of a string-backed case (Language::of(Locale::fr)), current when the page is in it. */
    public static function of(BackedEnum|string $code): self
    {
        $code = $code instanceof BackedEnum ? (string)$code->value : $code;

        return new self($code, $code === app()->getLocale());
    }

    /**
     * The language named in itself, whatever the page's, its first letter capitalised as a switcher lists it:
     * `English`, `Français`, `العربية`, `Español`. A name opening with a lower-case prefix keeps it: `isiZulu`.
     */
    public function name(): string
    {
        $name = Locale::getDisplayName($this->code, $this->code) ?: $this->code;

        return preg_match(self::LOWER_CASE_PREFIX, $name) === 1 ? $name : mb_ucfirst($name);
    }

    /**
     * The language's writing direction, for an element's `dir`: `rtl` for `ar`, `he`, `fa`, `ur`, a code in an RTL
     * script (`pa-Arab`) and the like.
     *
     * @return 'rtl'|'ltr'
     */
    public function dir(): string
    {
        return locale_is_right_to_left($this->code) ? 'rtl' : 'ltr';
    }
}
