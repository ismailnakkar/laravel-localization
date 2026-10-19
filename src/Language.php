<?php

declare(strict_types=1);

namespace Localization;

/** A Localization::languages() or suggestion() entry; $current: the page's language. */
final readonly class Language
{
    public function __construct(public string $code, public bool $current) {}
}
