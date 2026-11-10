<?php

declare(strict_types=1);

namespace Localization\Console;

/** @internal A localization:check row's outcome, printed as its value. */
enum CheckStatus: string
{
    case pass = 'PASS';
    case warn = 'WARN';
    case fail = 'FAIL';
}
