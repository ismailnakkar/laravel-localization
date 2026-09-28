<?php

declare(strict_types=1);

namespace Localization;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use LogicException;

/** A route's Route::localized() marker: its group's locales and the locale this copy serves. */
final readonly class LocalizedRoute
{
    /** @internal Route-action key; its value stays a plain array so route:cache can var_export it. */
    public const string ACTION = 'localization_locale';

    /** @internal Built by of(); $locales is internal too. */
    public function __construct(public Locales $locales, public string $locale) {}

    /** null outside Route::localized() */
    public static function of(?Route $route): ?self
    {
        $marker = $route?->getAction(self::ACTION);

        return is_array($marker) ? new self(new Locales($marker['codes'], $marker['default']), $marker['locale']) : null;
    }

    /**
     * This copy's $path ('/'-prefixed, as path info gives it) as $code's. On the fr copy, default en, '/fr/terms'
     * gives '/ar/terms' for ar and '/terms' for en.
     *
     * @throws LogicException $path is not a path of this copy
     */
    public function path(string $path, string $code): string
    {
        $base = $this->locale === $this->locales->default ? $path : self::withoutPrefix($path, $this->locale);

        // One leading slash, since a catch-all's `/fr//host` would leave `//host`, which a browser reads as a host.
        return $code === $this->locales->default ? '/' . ltrim($base, '/') : '/' . $code . rtrim($base, '/');
    }

    /**
     * @internal Strips `/$code`, even encoded ('/%66r%2Fterms' → '/terms', '/fr' → '/'). May leave a leading `//`.
     *
     * @throws LogicException $path does not open with that segment
     */
    public static function withoutPrefix(string $path, string $code): string
    {
        // The router matches the decoded path, so decode only the prefix and keep the rest as spelt.
        preg_match('~^(?:%[0-9A-Fa-f]{2}|.){' . (strlen($code) + 1) . '}~s', $path, $prefix);
        $rest = (string)preg_replace('~^%2F~i', '/', substr($path, strlen($prefix[0] ?? '')));

        if (rawurldecode($prefix[0] ?? '') !== '/' . $code || ! in_array(substr($rest, 0, 1), ['', '/'], true)) {
            throw new LogicException("[{$path}] is not a path of the {$code} copy.");
        }

        return $rest ?: '/';
    }

    /** @internal $route's name without this copy's `localization.{locale}.` prefix; null for an unnamed route. */
    public function name(Route $route): ?string
    {
        $name = (string)$route->getName();

        if ($this->locale !== $this->locales->default) {
            $name = Str::replaceFirst("localization.{$this->locale}.", '', $name);
        }

        return $name === '' ? null : $name;
    }

    /** @internal $route's URI without this copy's locale segment or outer slashes, the same on every copy. */
    public function unprefixedUri(Route $route): string
    {
        $uri = trim($route->uri(), '/');

        if ($this->locale === $this->locales->default) {
            return $uri;
        }

        return $uri === $this->locale ? '' : Str::after($uri, "{$this->locale}/");
    }
}
