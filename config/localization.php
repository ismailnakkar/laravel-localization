<?php

declare(strict_types=1);

return [
    // Codes like ['en', 'fr', 'zh-Hant']; the first is the default, unprefixed. Fewer than two localizes nothing.
    'locales' => [],

    // false: only a copy's URL sets the language; no session, account, POST /locale or entry redirect.
    'remember_locale' => true,

    // The user attribute the package reads and saves their language in. null: session only.
    'user_locale' => null,

    // Route names whose default copy redirects visitors arriving from outside the site to their language's copy.
    'entry_redirect' => [],
];
