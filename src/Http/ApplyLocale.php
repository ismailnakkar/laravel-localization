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
    /** Flashed by the /en/… hop: the bare copy it lands on was asked for by name, so no entry redirect undoes it. */
    private const string DEFAULT_ASKED = 'localization.default_asked';

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        $localized = LocalizedRoute::of($request->route());
        $user = $request->user();
        $account = UserLocale::of($user, $locales);
        // Known: the account or a choice. Never the browser, which is only a guess: that one is offered, not forced.
        $known = $account ?? ResolveLocale::stored($request, $locales);
        $target = $this->entryTarget($request, $locales, $localized, $known);

        if ($target !== null) {
            return redirect()->to($target);
        }

        $choice = ResolveLocale::choice($request, $locales, $account);
        $pageView = self::opensThePage($request);
        $hop = is_string($request->route()?->getAction(RedirectToDefaultCopy::ACTION));

        // Opening a copy records nothing: only a choice is kept. A page in another language suggests theirs instead.
        if ($request->hasSession()) {
            if ($pageView && $hop) {
                $request->session()->flash(self::DEFAULT_ASKED, true);
            }

            // So ResolveLocale, which never reads the user, speaks the account's language too.
            if ($account !== null && ResolveLocale::stored($request, $locales) !== $account) {
                $request->session()->put(ResolveLocale::PICKED_KEY, $account);
            }
        }

        // Page view only (a sibling's fetch can set Accept-Language); GET only (POST /locale saves the choice); not the
        // /en/… hop, which shows nothing: its landing fills it.
        if ($pageView && ! $hop && $request->isMethod('GET') && $account === null && UserLocale::hasColumn($user)) {
            // The language on screen: a bare copy opened from outside shows the default.
            rescue(static fn () => UserLocale::save($user, $localized->locale ?? $choice, unlessSet: $locales));
        }

        app()->setLocale($localized->locale ?? $choice);

        return $next($request);
    }

    /** A bare default copy names no language: from outside, it opens in the one the visitor told us, if any. */
    private function entryTarget(Request $request, Locales $locales, ?LocalizedRoute $localized, ?string $known): ?string
    {
        if (
            $known === null
            || $known === $locales->default
            || $localized === null
            || $localized->locale !== $locales->default
            || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))
            || $request->query->has('signature') // a signed URL pins its path
            || ($request->hasSession() && $request->session()->get(self::DEFAULT_ASKED) === true)
            || self::fromInsideTheSite($request)
            || self::isCrawler($request)
        ) {
            return null;
        }

        $query = (string)$request->server->get('QUERY_STRING');

        return $localized->path($request->getPathInfo(), $known) . ($query === '' ? '' : "?{$query}");
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

    /** @internal Octane: pass this request's server values; the default constructor reads a stale $_SERVER. */
    public static function isCrawler(Request $request): bool
    {
        $userAgent = (string)$request->userAgent();

        return $userAgent !== '' && new CrawlerDetect($request->server->all(), $userAgent)->isCrawler($userAgent);
    }
}
