<?php

declare(strict_types=1);

namespace Localization;

/** A Localization::languages() or suggestion() entry; $current: the page's language. */
final readonly class Language
{
    public function __construct(public string $code, public bool $current) {}

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
