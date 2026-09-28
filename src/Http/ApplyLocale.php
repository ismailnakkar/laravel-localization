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

/** Adds the account's language and the entry redirect, after AuthenticateSession has checked the session. */
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

        // Redirect before saving, or an arrival on the default copy would save the default over the choice.
        if ($target !== null) {
            return redirect()->to($target);
        }

        $pageView = self::opensThePage($request);
        // Typing the default's prefix (/en/…) asks for the default, so the entry redirect must not undo it.
        $redirect = $request->route()?->getAction(RedirectToDefaultCopy::ACTION);
        $opened = $pageView ? ($localized->locale ?? (is_string($redirect) ? $redirect : null)) : null;

        // Only a change, so the login copy `auth` picked from the browser's language never outranks the account.
        if ($opened !== null && $opened !== $choice && $request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $opened);
        }

        // Only a page view, since a sibling's fetch can set Accept-Language, and only a GET, since POST /locale saves
        // the choice itself.
        if ($pageView && $request->isMethod('GET') && $account === null && UserLocale::hasColumn($user)) {
            rescue(static fn () => UserLocale::save($user, $opened ?? $choice, unlessSet: $locales));
        }

        app()->setLocale($localized->locale ?? $choice);

        return $next($request);
    }

    /** The choice's copy of an entry_redirect page, for a visitor arriving from outside the site; null to render. */
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
     * Cross-origin, only a top-level GET: cookies follow an <img>, iframe or forged POST, and CSRF may run later.
     * Same origin or no Fetch Metadata (old browser): a page load or the app's own fetch (Inertia, wire:navigate),
     * never a user-content <img>/iframe. Never a signed link (its sender chose the language), or a Livewire update
     * replaying one unsigned.
     */
    private static function opensThePage(Request $request): bool
    {
        $dest = $request->headers->get('Sec-Fetch-Dest');

        return ! $request->query->has('signature') && ! $request->headers->has('X-Livewire')
            && (in_array($request->headers->get('Sec-Fetch-Site'), [null, 'same-origin'], true)
            ? in_array($dest, [null, 'document', 'empty'], true)
            : $request->isMethod('GET') && $dest === 'document');
    }

    /** Sec-Fetch-Site, or where a browser sends none (Safari before 16.4, plain HTTP), a Referer on this host. */
    private static function fromInsideTheSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($site !== null) {
            return $site === 'same-origin';
        }

        $referer = parse_url((string)$request->headers->get('Referer'), PHP_URL_HOST);

        return is_string($referer) && strcasecmp($referer, $request->getHost()) === 0;
    }

    /** Built from this request's own server values: the default constructor reads $_SERVER, stale under Octane. */
    private static function isCrawler(Request $request): bool
    {
        $userAgent = (string)$request->userAgent();

        return $userAgent !== '' && new CrawlerDetect($request->server->all(), $userAgent)->isCrawler($userAgent);
    }
}
