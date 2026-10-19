<?php

declare(strict_types=1);

return [
    // e.g. ['en', 'fr', 'zh-Hant']; the first is the unprefixed default. Fewer than two: all but numbers off.
    'locales' => [],

    // User attribute holding their language; null: session only.
    'user_locale' => null,

    // ICU numbering system for Laravel's Number in the page language, e.g. 'latn' (Latin digits in Arabic); null: off.
    'numbers' => null,
];
