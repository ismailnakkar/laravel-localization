<?php

declare(strict_types=1);

namespace Localization\Http;

use Illuminate\Http\Request;

/** @internal A query kept as the visitor spelt it, and Laravel's signed-URL parameters. */
final class QueryString
{
    public const string SIGNATURE = 'signature';

    public const string EXPIRES = 'expires';

    /** As spelt: getQueryString() sorts and re-encodes it. */
    public static function of(Request $request): string
    {
        return (string)$request->server->get('QUERY_STRING');
    }

    public static function append(string $path, string $query): string
    {
        return $query === '' ? $path : "{$path}?{$query}";
    }

    /** A signed URL's signature covers its path and query, and its sender chose the language. */
    public static function isSigned(Request $request): bool
    {
        return $request->query->has(self::SIGNATURE);
    }
}
