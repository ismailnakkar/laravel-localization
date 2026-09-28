<?php

declare(strict_types=1);

namespace Localization\Http;

use Closure;
use Illuminate\Http\Request;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Localization\Locales;
use Localization\LocalizedRoute;
use Localization\UserLocale;
use Symfony\Component\HttpFoundation\Response;

final class ApplyLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        $localized = LocalizedRoute::of($request->route());
        $user = $request->user();
        $account = UserLocale::of($user, $locales);
        $choice = ResolveLocale::choice($request, $locales, $account);
        $target = $this->entryTarget($request, $locales, $localized, $choice);

        // Redirect before saving, or arriving on the default copy would save the default over the choice.
        if ($target !== null) {
            return redirect()->to($target);
        }

        $pageView = self::opensThePage($request);
        // Typing /en/… asks for the default; the entry redirect must not undo it.
        $redirect = $request->route()?->getAction(RedirectToDefaultCopy::ACTION);
        $opened = $pageView ? ($localized->locale ?? (is_string($redirect) ? $redirect : null)) : null;

        // Only a change, so a login copy picked from Accept-Language never outranks the account.
        if ($opened !== null && $opened !== $choice && $request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $opened);
        }

        // Page view only (a sibling's fetch can set Accept-Language); GET only (POST /locale saves the choice).
        if ($pageView && $request->isMethod('GET') && $account === null && UserLocale::hasColumn($user)) {
            rescue(static fn () => UserLocale::save($user, $opened ?? $choice, unlessSet: $locales));
        }

        app()->setLocale($localized->locale ?? $choice);

        return $next($request);
    }

    private function entryTarget(Request $request, Locales $locales, ?LocalizedRoute $localized, string $choice): ?string
    {
        $name = $request->route()?->getName();

        if (
            $localized === null
            || $localized->locale !== $locales->default
            || $choice === $locales->default
            || ! in_array($name, (array)config('localization.entry_redirect'), true)
            || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))
            || $request->query->has('signature') // a signed URL pins its path
            || self::fromInsideTheSite($request)
            || self::isCrawler($request)
        ) {
            return null;
        }

        $query = (string)$request->server->get('QUERY_STRING');

        return $localized->path($request->getPathInfo(), $choice) . ($query === '' ? '' : "?{$query}");
    }

    /**
     * Cross-origin: top-level GET only; cookies ride an <img>, iframe or forged POST, and CSRF may run later.
     * Same-origin or no Fetch Metadata: a page load or the app's fetch (Inertia, wire:navigate), not an <img>/iframe.
     * Never a signed link (its sender chose the language) or a Livewire update replaying one unsigned.
     */
    private static function opensThePage(Request $request): bool
    {
        $dest = $request->headers->get('Sec-Fetch-Dest');

        return ! $request->query->has('signature') && ! $request->headers->has('X-Livewire')
            && (in_array($request->headers->get('Sec-Fetch-Site'), [null, 'same-origin'], true)
            ? in_array($dest, [null, 'document', 'empty'], true)
            : $request->isMethod('GET') && $dest === 'document');
    }

    /** Referer fallback: Safari before 16.4 and plain HTTP send no Sec-Fetch-Site. */
    private static function fromInsideTheSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($site !== null) {
            return $site === 'same-origin';
        }

        $referer = parse_url((string)$request->headers->get('Referer'), PHP_URL_HOST);

        return is_string($referer) && strcasecmp($referer, $request->getHost()) === 0;
    }

    /** Octane: pass this request's server values; the default constructor reads a stale $_SERVER. */
    private static function isCrawler(Request $request): bool
    {
        $userAgent = (string)$request->userAgent();

        return $userAgent !== '' && new CrawlerDetect($request->server->all(), $userAgent)->isCrawler($userAgent);
    }
}
