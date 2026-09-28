<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use LogicException;

final readonly class LocalizedRoute
{
    /** @internal Route-action key; its value stays a plain array so route:cache can var_export it. */
    public const string ACTION = 'localization_locale';

    /** @internal */
    public function __construct(public Locales $locales, public string $locale) {}

    /** null outside Route::localized() */
    public static function of(?Route $route): ?self
    {
        $marker = $route?->getAction(self::ACTION);

        return is_array($marker) ? new self(new Locales($marker['codes'], $marker['default']), $marker['locale']) : null;
    }

    /**
     * This copy's $path as $code's: on the fr copy, '/fr/terms' → '/terms' (default en), '/ar/terms' (ar).
     *
     * @throws LogicException
     */
    public function path(string $path, string $code): string
    {
        $base = $this->locale === $this->locales->default ? $path : self::withoutPrefix($path, $this->locale);

        // One leading slash: a catch-all's `/fr//host` would leave `//host`, a host to browsers.
        return $code === $this->locales->default ? '/' . ltrim($base, '/') : '/' . $code . rtrim($base, '/');
    }

    /**
     * @internal May leave a leading `//`.
     *
     * @throws LogicException
     */
    public static function withoutPrefix(string $path, string $code): string
    {
        // The router matches decoded paths: decode the prefix only, keep the rest as spelt.
        preg_match('~^(?:%[0-9A-Fa-f]{2}|.){' . (strlen($code) + 1) . '}~s', $path, $prefix);
        $rest = (string)preg_replace('~^%2F~i', '/', substr($path, strlen($prefix[0] ?? '')));

        if (rawurldecode($prefix[0] ?? '') !== '/' . $code || ! in_array(substr($rest, 0, 1), ['', '/'], true)) {
            throw new LogicException("[{$path}] is not a path of the {$code} copy.");
        }

        return $rest ?: '/';
    }

    /** @internal */
    public function name(Route $route): ?string
    {
        $name = (string)$route->getName();

        if ($this->locale !== $this->locales->default) {
            $name = Str::replaceFirst("localization.{$this->locale}.", '', $name);
        }

        return $name === '' ? null : $name;
    }

    /** @internal */
    public function unprefixedUri(Route $route): string
    {
        $uri = trim($route->uri(), '/');

        if ($this->locale === $this->locales->default) {
            return $uri;
        }

        return $uri === $this->locale ? '' : Str::after($uri, "{$this->locale}/");
    }
}
