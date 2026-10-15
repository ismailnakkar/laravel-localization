<?php

declare(strict_types=1);

return [
    // e.g. ['en', 'fr', 'zh-Hant']; the first is the unprefixed default. Fewer than two: all but numbers off.
    'locales' => [],

    // User attribute holding their language; null: session only.
    'user_locale' => null,

    // Laravel's Number formats in the page language with this ICU numbering system, e.g. 'latn' (Latin digits in
    // Arabic); null leaves Number alone.
    'numbers' => null,
];
