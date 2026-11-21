<?php

declare(strict_types=1);

return [
    // e.g. ['en', 'fr', 'zh-Hant'], default first and unprefixed. Fewer than two: only numbers and date_locales apply.
    'locales' => [],

    // User attribute holding their language; null: session only.
    'user_locale' => null,

    // ICU numbering system for Laravel's Number and IntlDate, e.g. 'latn' (Latin digits in Arabic); null: off.
    'numbers' => null,

    // App locale => ICU locale IntlDate prints in, e.g. ['en' => 'en-GB'] (British dates); unlisted: the app locale.
    'date_locales' => [],

    // Query parameter older URLs carried the language in, e.g. 'lang': ?lang=fr 301s to the fr copy; null: off.
    'legacy_query' => null,
];
