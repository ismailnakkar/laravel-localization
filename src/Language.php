<?php

declare(strict_types=1);

namespace Localization;

/** A Localization::languages() entry; $current marks the language on screen. */
final readonly class Language
{
    public function __construct(public string $code, public bool $current) {}
}
