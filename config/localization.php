<?php

declare(strict_types=1);

return [
    // e.g. ['en', 'fr', 'zh-Hant']; the first is the unprefixed default. Fewer than two: off.
    'locales' => [],

    // false: only the URL sets the language; no session, account, POST /locale or entry redirect.
    'remember_locale' => true,

    // User attribute holding their language; null: session only.
    'user_locale' => null,
];
