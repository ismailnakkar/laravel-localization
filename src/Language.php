<?php

declare(strict_types=1);

namespace Localization;

/** One Localization::languages() entry; current marks the language on screen. Labels are the app's own text. */
final readonly class Language
{
    public function __construct(public string $code, public bool $current) {}
}
