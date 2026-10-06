<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Localization\Http\ApplyLocale;
use Localization\Http\ResolveLocale;
use Localization\Tests\Fixtures\Admin;
use Localization\Tests\Fixtures\AuthenticateSession as AppAuthenticateSession;
use Localization\Tests\Fixtures\LocaleCode;
use Localization\Tests\Fixtures\RequireRecentSignIn;
use Localization\Tests\Fixtures\User;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class ResolveLocaleTest extends LanguagesTestCase
{
    protected function defineWebRoutes($router): void
    {
        $locale = static fn (): string => app()->getLocale();
        // Login-as: swaps users after ApplyLocale ran.
        $loginAs = static function (Request $request) use ($locale): string {
            Auth::onceUsingId((int)$request->route('member'));

            return $locale();
        };
        $signIn = static function () use ($locale): string {
            Auth::login(User::query()->where('name', 'member')->firstOrFail());

            return $locale();
        };

        // CSRF reads the session: VerifyCsrfToken on Laravel 12, PreventRequestForgery on 13.
        $sessionless = [StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, PreventRequestForgery::class];

        $router->localized(static function (Router $router) use ($locale, $loginAs, $signIn, $sessionless): void {
            $router->match(['GET', 'HEAD', 'POST', 'QUERY'], '/', $locale)->name('home');
            $router->get('sessionless-copy', $locale)->withoutMiddleware($sessionless);
            $router->get('terms', $locale)->name('terms');
            $router->get('login', $locale)->name('login');
            $router->get('login-as/{member}', $loginAs);
            $router->post('sign-up', static function (): string {
                Auth::login(User::create(['name' => 'new']));

                return app()->getLocale();
            });
            $router->post('sign-in', $signIn);
        });
        $router->get('plain', $locale);
        $router->post('members/sign-in', $signIn);
        $router->get('members', $locale)->middleware('auth')->name('members');
        $router->get('admin', $locale)->middleware('auth:admin');
        $router->get('limited', $locale)->middleware('throttle:1,1');
        $router->get('recent', $locale)->middleware(RequireRecentSignIn::class);
        $router->get('as/{member}', $loginAs);
        $router->get('sessionless', $locale)->withoutMiddleware($sessionless);
    }

    public function test_the_choice_runs_straight_after_the_session_and_the_account_straight_after_authenticate_session(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $priority = $kernel->getMiddlewarePriority();

        $this->assertContains(ResolveLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertContains(ApplyLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertSame((int)array_search(StartSession::class, $priority, true) + 1, array_search(ResolveLocale::class, $priority, true));
        $this->assertSame((int)array_search(AuthenticatesSessions::class, $priority, true) + 1, array_search(ApplyLocale::class, $priority, true));
    }

    /** An app's withMiddleware(), ranked per the README. */
    protected function sessionCheckRankedEarly(FoundationApplication $app): void
    {
        new ApplicationBuilder($app)->withMiddleware(static function (Middleware $middleware): void {
            $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: AppAuthenticateSession::class);
            $middleware->appendToPriorityList(after: AppAuthenticateSession::class, append: ApplyLocale::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: PreventRequestForgery::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: VerifyCsrfToken::class); // Laravel 12's
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireRecentSignIn::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ValidateSignature::class);
            $middleware->web(append: AppAuthenticateSession::class);
        });
    }

    #[DefineEnvironment('sessionCheckRankedEarly')]
    public function test_an_app_ranking_the_account_after_its_early_session_check_has_its_gates_refuse_in_the_accounts_language(): void
    {
        $this->app->make(Kernel::class); // runs the app's hook, then the package's
        $router = $this->app->make(Router::class);
        $order = [ResolveLocale::class, AppAuthenticateSession::class, ApplyLocale::class, RequireRecentSignIn::class];
        $this->assertSame($order, array_values(array_intersect($router->gatherRouteMiddleware($router->getRoutes()->match(Request::create('/recent'))), $order)));

        $this->assertARevokedSessionWritesNothing();

        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now']);
        $this->actingAs($member)->withHeaders(['Accept-Language' => 'es'])->get('/recent')->assertStatus(423)->assertContent('fr');
    }

    protected function priorityWithoutAuthenticateSession(Application $app): void
    {
        $app->afterResolving(Kernel::class, static function (HttpKernel $kernel): void {
            // Not StartSession first: older Laravel ranks "after the first entry" last.
            $kernel->setMiddlewarePriority([EncryptCookies::class, StartSession::class, AuthenticatesRequests::class, SubstituteBindings::class]);
            $kernel->appendMiddlewareToGroup('web', AuthenticateSession::class);
        });
    }

    #[DefineEnvironment('priorityWithoutAuthenticateSession')]
    public function test_a_priority_list_without_authenticate_session_still_puts_the_account_after_both_auth_checks(): void
    {
        $this->app->make(Kernel::class); // runs the app's hook, then the package's
        $router = $this->app->make(Router::class);
        $route = $router->getRoutes()->getByName('members');
        assert($route !== null);

        $order = array_values(array_intersect(
            $router->gatherRouteMiddleware($route),
            [ResolveLocale::class, Authenticate::class, AuthenticateSession::class, ApplyLocale::class],
        ));

        $this->assertCount(4, $order);
        $this->assertSame(ResolveLocale::class, $order[0]);
        $this->assertSame(ApplyLocale::class, $order[3]);
    }

    public function test_a_revoked_remember_me_cookie_is_not_signed_in_by_the_entry_redirect(): void
    {
        $this->withAuthenticateSession();
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);

        // Old password: newer guards refuse it, older ones leave it to AuthenticateSession.
        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-before")->get('/');

        $this->assertGuestNextTime();
    }

    public function test_a_revoked_session_never_writes_the_account(): void
    {
        $this->withAuthenticateSession();

        $this->assertARevokedSessionWritesNothing();
    }

    public function test_a_valid_remember_me_cookie_still_lands_on_the_accounts_language(): void
    {
        $this->withAuthenticateSession();
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);

        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-now")->get('/')->assertRedirect('/fr');
    }

    public function test_the_choice_never_signs_in_a_remember_me_cookie_for_a_refusal_ahead_of_authenticate_session(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);
        // Unit tests skip CSRF.
        $this->app->offsetSet('env', 'production');

        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-now")->post('/')->assertStatus(419);

        $this->assertGuestNextTime();
    }

    public function test_a_guest_turned_away_by_auth_lands_on_the_login_page_in_their_language(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->get('/members')->assertRedirect('/fr/login');
    }

    public function test_a_copy_renders_its_own_language_and_opening_it_changes_no_choice(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr']);

        $this->get('/es/terms')->assertContent('es');
        $this->get('/plain')->assertContent('fr');
        $this->get('/terms', ['Sec-Fetch-Site' => 'same-origin'])->assertContent('en');
        $this->get('/plain')->assertContent('fr');

        $this->assertSame('fr', session(ResolveLocale::PICKED_KEY));
    }

    /** @return iterable<string, array{string, array<string, string>}> URI, headers */
    public static function pageViews(): iterable
    {
        yield 'a prefixed copy' => ['/es/terms', []];
        yield "the default's prefix, typed" => ['/en/terms', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document']];
        yield 'the default copy, clicked inside the site' => ['/terms', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document']];
        yield 'the default copy, clicked inside the site, by Referer' => ['/terms', ['Referer' => 'http://localhost/fr/terms']];
        yield 'the default copy, typed or bookmarked' => ['/terms', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document']];
        yield 'the default copy, from another site' => ['/terms', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document']];
        yield 'the default copy, no Fetch Metadata nor Referer' => ['/terms', []];
        yield 'an <img> on another site' => ['/fr/terms', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image']];
        yield 'a forwarded signed link' => ['/fr/terms?signature=x', []];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('pageViews')]
    public function test_no_way_of_opening_a_copy_changes_the_language_of_pages_without_one(string $uri, array $headers): void
    {
        $this->withHeaders(['Accept-Language' => 'ar', ...$headers])->get($uri);

        $this->assertNull(session(ResolveLocale::PICKED_KEY));
        $this->get('/plain')->assertContent('ar');
    }

    /** Following a link to a copy shows it; pages without a language in the URL keep the browser's. */
    public function test_a_guest_following_a_link_to_a_copy_keeps_their_language_elsewhere_and_nothing_is_written(): void
    {
        $this->withHeaders(['Accept-Language' => 'en'])->get('/fr/terms', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->assertContent('fr');
        $this->get('/plain', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document'])->assertContent('en');

        // The package's keys.
        $this->assertNull(session('localization'));
    }

    public function test_a_member_opening_a_copy_keeps_their_accounts_language_elsewhere_and_only_the_account_is_mirrored(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'en']);

        $this->actingAs($member)->get('/fr/terms', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->assertContent('fr');
        $this->get('/plain')->assertContent('en');

        $this->assertSame(['picked' => 'en'], session('localization'));
        $this->assertSame('en', $member->fresh()?->locale);
    }

    public function test_the_account_beats_a_pick_and_the_browser(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($user)->withSession([ResolveLocale::PICKED_KEY => 'es'])
            ->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertContent('ar');

        $this->assertSame('ar', $user->fresh()?->locale);
    }

    public function test_a_members_account_is_mirrored_into_the_pick_so_a_refusal_ahead_of_the_account_speaks_it(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($member)->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/limited')->assertOk();
        $this->app->setLocale('en');

        $this->get('/limited')->assertStatus(429);

        $this->assertSame('ar', $this->app->getLocale());
        $this->assertSame('ar', session(ResolveLocale::PICKED_KEY));
    }

    public function test_an_enum_cast_account_language_counts(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'fr']);
        $user->mergeCasts(['locale' => LocaleCode::class]);

        $this->actingAs($user)->get('/plain')->assertContent('fr');
    }

    /** A week idle, a sign-out: the session ends, and the cookie the switcher set keeps what the guest chose. */
    public function test_a_guests_choice_outlives_the_session_through_its_cookie(): void
    {
        $this->withCookie(ResolveLocale::COOKIE, 'fr')->withHeader('Accept-Language', 'es')->get('/plain')->assertContent('fr');
        $this->withHeaders(['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])->get('/terms')->assertRedirect('/fr/terms');

        // The session's choice is the newer one; a code no longer configured is none.
        $this->withSession([ResolveLocale::PICKED_KEY => 'ar'])->get('/plain')->assertContent('ar');
        $this->flushSession();
        $this->withCookie(ResolveLocale::COOKIE, 'de')->get('/plain')->assertContent('es');
    }

    public function test_a_pick_beats_the_browser(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'es'])->withHeaders(['Accept-Language' => 'ar'])->get('/plain')->assertContent('es');
        session()->forget(ResolveLocale::PICKED_KEY);

        $this->get('/plain')->assertContent('ar');
    }

    public function test_the_browser_then_the_default(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr-CA,en;q=0.5'])->get('/plain')->assertContent('fr');

        $this->flushSession();
        $this->withHeaders(['Accept-Language' => ''])->get('/plain')->assertContent('en');
    }

    public function test_a_saved_code_no_longer_configured_falls_through(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'de'])->withHeaders(['Accept-Language' => 'es'])->get('/plain')->assertContent('es');
    }

    public function test_an_account_without_a_language_takes_the_copys(): void
    {
        // Explicit null: only a loaded, empty column is filled.
        $user = User::create(['name' => 'member', 'locale' => null]);

        $this->actingAs($user)->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/fr/terms')->assertContent('fr');

        $this->assertSame('fr', $user->fresh()?->locale);
    }

    public function test_a_copy_opened_by_any_method_never_outranks_nor_writes_the_account(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($member)->get('/fr')->assertContent('fr');
        $this->post('/es')->assertContent('es');
        $this->get('/members')->assertContent('ar');

        $this->assertSame('ar', $member->fresh()?->locale);
    }

    public function test_a_copy_in_the_accounts_language_never_writes_the_account(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'fr']);
        $user->mergeCasts(['locale' => LocaleCode::class]);
        $codes = [];
        $this->localization()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });

        $this->actingAs($user)->get('/fr/terms')->assertContent('fr');
        $this->get('/plain')->assertContent('fr');

        $this->assertSame([], $codes);
        $this->assertSame('fr', session(ResolveLocale::PICKED_KEY));
    }

    public function test_a_click_inside_the_site_onto_the_default_copy_shows_it_and_switches_nothing(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr']);

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get('/')->assertOk()->assertContent('en');
        $this->get('/plain')->assertContent('fr');

        $this->assertSame('fr', session(ResolveLocale::PICKED_KEY));
        $this->assertSame('fr', $member->fresh()?->locale);
    }

    public function test_a_signed_link_never_switches_to_its_language(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr']);
        $url = URL::signedRoute('terms');

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->get($url)->assertOk()->assertContent('en');

        $this->assertSame('fr', $member->fresh()?->locale);
        $this->get('/plain')->assertContent('fr');
    }

    /** @return iterable<string, array{array<string, string>, bool}> headers, whether it is a page view */
    public static function loadsOfACopy(): iterable
    {
        // SameSite=None cookies follow a cross-site <img>.
        yield 'an <img> on another site' => [['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'], false];
        yield 'an <img> on the same origin' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'image'], false];
        yield 'an <iframe> on the same site' => [['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Dest' => 'iframe'], false];
        yield 'a fetch from a sibling subdomain' => [['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Dest' => 'empty'], false];
        yield 'a page visit from a sibling subdomain' => [['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Dest' => 'document'], true];
        yield 'typed or bookmarked' => [['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'], true];
        yield 'a click on the same origin' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document'], true];
        yield 'a prefetch on the same origin, served for the click' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch'], true];
        yield 'a fetch on the same origin: Inertia, wire:navigate' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty', 'X-Livewire-Navigate' => '1'], true];
        yield 'a Livewire update replaying the page, which drops its query' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty', 'X-Livewire' => '1'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('loadsOfACopy')]
    public function test_only_a_page_load_or_the_apps_own_fetch_of_a_copy_fills_an_empty_account_and_none_changes_the_pick(array $headers, bool $pageView): void
    {
        $member = User::create(['name' => 'member', 'locale' => null]);

        $this->actingAs($member)->withSession([ResolveLocale::PICKED_KEY => 'es'])->withHeaders($headers)->get('/ar')->assertOk()->assertContent('ar');

        $this->assertSame($pageView ? 'ar' : null, $member->fresh()?->locale);
        $this->assertSame('es', session(ResolveLocale::PICKED_KEY));
    }

    public function test_a_stale_cached_user_never_replaces_the_account_its_row_holds(): void
    {
        $stale = User::create(['name' => 'member', 'locale' => 'ar'])->setRawAttributes(['locale' => null] + User::query()->firstOrFail()->getAttributes(), true);
        $codes = [];

        $this->actingAs($stale)->get('/es/terms')->assertContent('es');
        $this->localization()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });
        $this->get('/fr/terms')->assertContent('fr');

        $this->assertSame('ar', User::query()->value('locale'));
        $this->assertSame([], $codes);
    }

    public function test_only_a_page_view_fills_an_empty_account(): void
    {
        $member = User::create(['name' => 'member', 'locale' => null]);
        $this->actingAs($member);

        $this->get('/plain', ['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Dest' => 'empty', 'Accept-Language' => 'ar'])->assertContent('ar');
        $this->get('/plain', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'image', 'Accept-Language' => 'ar'])->assertContent('ar');
        $this->get('/plain', ['X-Livewire' => 'true', 'Accept-Language' => 'ar'])->assertContent('ar');
        $this->assertNull($member->fresh()?->locale);

        $this->get('/plain', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'fr'])->assertContent('fr');
        $this->assertSame('fr', $member->fresh()?->locale);
    }

    /** A bare copy opened from outside shows the default: the account takes that, never the browser's guess. */
    public function test_a_bare_copy_opened_from_outside_fills_an_empty_account_with_what_it_shows(): void
    {
        $member = User::create(['name' => 'member', 'locale' => null]);

        $this->actingAs($member)->get('/terms', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'fr'])
            ->assertOk()->assertContent('en');

        $this->assertSame('en', $member->fresh()?->locale);
    }

    /** The /en/… hop shows nothing, so it fills nothing: the English page it lands on does. */
    public function test_typing_the_defaults_prefix_fills_an_empty_account_with_the_default_never_the_browsers(): void
    {
        $member = User::create(['name' => 'member', 'locale' => null]);
        $arrival = ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'fr'];

        $this->actingAs($member)->get('/en/terms', $arrival)->assertStatus(301);
        $this->assertNull($member->fresh()?->locale);

        $this->get('/terms', $arrival)->assertOk()->assertContent('en');
        $this->assertSame('en', $member->fresh()?->locale);
    }

    #[DefineEnvironment('sessionCheckRankedEarly')]
    public function test_a_forged_post_refused_by_csrf_ranked_after_the_account_saves_nothing(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'en', 'password' => 'hash-now']);
        $codes = [];
        $this->localization()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });
        $this->app->offsetSet('env', 'production');

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->post('/ar')->assertStatus(419);

        $this->assertSame([], $codes);
    }

    /** Not the copy it signed up on: opening that recorded nothing. */
    public function test_a_new_account_takes_the_language_of_the_first_page_it_sees(): void
    {
        $this->withHeaders(['Accept-Language' => 'es'])->post('/fr/sign-up')->assertContent('fr');
        // Reload the user: the instance sign-up created lacks the column.
        Auth::forgetGuards();
        $this->get('/plain')->assertContent('es');

        $this->assertSame('es', User::query()->where('name', 'new')->value('locale'));
    }

    public function test_signing_in_on_a_copy_keeps_the_account_and_the_members_area_speaks_it(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/fr/login')->assertContent('fr');
        $this->post('/fr/sign-in')->assertContent('fr');

        $this->assertSame('ar', $member->fresh()?->locale);
        $this->get('/members')->assertOk()->assertContent('ar');
    }

    /** @return iterable<string, array{string, string}> the browser's language, the login page's prefix */
    public static function newDevices(): iterable
    {
        yield 'a browser in another language' => ['es', '/es'];
        yield 'a browser in none of them' => ['de', ''];
    }

    #[DataProvider('newDevices')]
    public function test_signing_in_on_the_copy_the_browser_picked_keeps_the_accounts_language(string $browser, string $prefix): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->withHeaders(['Accept-Language' => $browser])->get('/members')->assertRedirect("{$prefix}/login");
        $this->post("{$prefix}/sign-in")->assertOk();

        $this->assertSame('ar', $member->fresh()?->locale);
        $this->get('/members')->assertContent('ar');
    }

    /** @return iterable<string, array{string, ?string, string}> URI, the account's language before and after */
    public static function signIns(): iterable
    {
        yield 'off a copy' => ['/members/sign-in', 'ar', 'ar'];
        yield 'through a signed link' => ['/fr/sign-in?signature=x', 'ar', 'ar'];
        yield 'through a signed link, an account without a language' => ['/fr/sign-in?signature=x', null, 'es'];
        yield 'on a copy, an account without a language' => ['/fr/sign-in', null, 'es'];
    }

    #[DataProvider('signIns')]
    public function test_signing_in_keeps_the_account_else_fills_it_with_the_visitors_language(string $uri, ?string $before, string $after): void
    {
        $member = User::create(['name' => 'member', 'locale' => $before]);
        $codes = [];
        $this->localization()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
            User::query()->whereKey($user->getKey())->update(['locale' => $code]);
        });

        $this->withHeaders(['Accept-Language' => 'es'])->post($uri)->assertOk();
        $this->get('/plain')->assertContent($after);

        $this->assertSame($before === $after ? [] : [$after], $codes);
        $this->assertSame($after, $member->fresh()?->locale);
    }

    public function test_an_admin_signed_in_as_a_member_never_writes_the_members_account(): void
    {
        $admin = User::create(['name' => 'admin', 'locale' => 'ar']);
        $member = User::create(['name' => 'member']);

        $this->actingAs($admin)->get("/as/{$member->id}")->assertOk();

        $this->assertNull($member->fresh()?->locale);
        $this->assertSame('ar', $admin->fresh()?->locale);
    }

    public function test_an_admin_signed_in_as_a_member_on_a_copy_writes_neither_account(): void
    {
        $admin = User::create(['name' => 'admin', 'locale' => 'ar']);
        $member = User::create(['name' => 'member']);

        $this->actingAs($admin)->get("/fr/login-as/{$member->id}")->assertOk()->assertContent('fr');

        $this->assertNull($member->fresh()?->locale);
        $this->assertSame('ar', $admin->fresh()?->locale);
    }

    public function test_a_failed_fill_is_reported_and_the_page_still_served(): void
    {
        $user = User::create(['name' => 'member', 'locale' => null]);
        Exceptions::fake();
        Event::listen('eloquent.saving: ' . User::class, static fn () => throw new RuntimeException('database down'));

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'database down');
    }

    public function test_a_fill_goes_through_the_saving_closure_with_the_requests_user_once(): void
    {
        $user = User::create(['name' => 'member', 'locale' => null]);
        $calls = [];
        $this->localization()->saveUserLocaleUsing(static function (User $model, string $code) use (&$calls): void {
            $calls[] = [$model, $code];
            User::query()->whereKey($model->getKey())->update(['locale' => $code]);
        });

        $this->actingAs($user)->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/plain')->assertContent('es');
        $this->get('/fr/terms')->assertContent('fr');

        $this->assertSame([[$user, 'es']], $calls);
        $this->assertSame('es', $user->fresh()?->locale);
        $this->assertSame('es', $user->locale);
        $this->assertFalse($user->isDirty('locale'));
    }

    public function test_a_throwing_closure_in_a_fill_is_reported_and_the_page_still_served(): void
    {
        Exceptions::fake();
        $this->localization()->saveUserLocaleUsing(static fn () => throw new RuntimeException('service down'));
        $user = User::create(['name' => 'member', 'locale' => null]);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'service down');
        $this->assertNull($user->locale);
    }

    /** @return iterable<string, array{bool, bool}> a member signed in too, preventAccessingMissingAttributes() */
    public static function adminPages(): iterable
    {
        yield 'an admin and a member' => [true, false];
        yield 'an admin and a member, strict' => [true, true];
        yield 'an admin' => [false, false];
        yield 'an admin, strict' => [false, true];
    }

    #[DataProvider('adminPages')]
    public function test_another_guards_model_without_the_column_is_left_alone(bool $member, bool $strict): void
    {
        $this->createAdminsTable();
        config([
            'auth.guards.admin'     => ['driver' => 'session', 'provider' => 'admins'],
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => Admin::class],
        ]);
        Exceptions::fake();

        if ($member) {
            $this->actingAs(User::create(['name' => 'member', 'locale' => 'fr']));
        }

        $this->actingAs(Admin::create(), 'admin');
        // actingAs() made admin the default guard; only auth:admin may.
        Auth::shouldUse('web');
        Model::preventAccessingMissingAttributes($strict);
        DB::enableQueryLog();

        try {
            $this->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/admin')->assertOk()->assertContent('es');
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        Exceptions::assertNothingReported();
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_refusal_ahead_of_the_route_speaks_the_visitors_language(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->get('/limited')->assertOk();
        $this->app->setLocale('en');

        $this->get('/limited')->assertStatus(429);

        $this->assertSame('fr', $this->app->getLocale());
    }

    public function test_a_refusal_on_a_copy_speaks_the_copys_language(): void
    {
        $this->app->offsetSet('env', 'production');

        $this->withSession([ResolveLocale::PICKED_KEY => 'es'])->post('/fr/sign-up')->assertStatus(419);

        $this->assertSame('fr', $this->app->getLocale());
    }

    public function test_a_refused_request_switches_nothing(): void
    {
        $this->app->offsetSet('env', 'production');

        $this->post('/es')->assertStatus(419);

        $this->get('/plain')->assertContent('en');
    }

    public function test_a_route_without_a_session_still_resolves(): void
    {
        $this->withHeaders(['Accept-Language' => 'es'])->get('/sessionless')->assertOk()->assertContent('es');
        $this->withHeaders(['Accept-Language' => 'es'])->get('/fr/sessionless-copy')->assertOk()->assertContent('fr');
    }

    public function test_a_signed_in_user_that_is_not_an_eloquent_model_is_left_alone(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]))->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');
    }

    public function test_carbon_follows_the_page(): void
    {
        $this->get('/fr/terms');

        $this->assertSame('fr', Carbon::getLocale());
    }

    public function test_an_arrival_from_outside_goes_to_the_picked_languages_copy_with_the_query_untouched(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->get('/?utm_source=x&b=2&a=1')
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/fr?utm_source=x&b=2&a=1');

        $this->head('/')->assertStatus(302);
    }

    public function test_a_browser_language_alone_never_redirects_nor_is_recorded(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr', 'Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])->get('/')->assertOk()->assertContent('en');

        $this->assertNull(session('localization'));
    }

    public function test_a_member_typing_the_domain_lands_on_their_accounts_language_over_a_pick(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($member)->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/')->assertRedirect('/ar');

        $this->assertSame('ar', $member->fresh()?->locale);
    }

    public function test_a_bookmark_to_a_default_copy_never_switches_a_member_off_their_accounts_language(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr']);

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])->get('/terms')->assertRedirect('/fr/terms');

        $this->get('/plain')->assertContent('fr');
    }

    public function test_a_guest_who_picked_a_language_is_sent_to_its_copy_of_any_page_from_a_bookmark(): void
    {
        $this->post('/locale', ['locale' => 'fr', 'to' => '/'])->assertRedirect('/fr');

        $this->withHeaders(['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])->get('/terms')->assertRedirect('/fr/terms');
    }

    public function test_the_redirect_covers_every_localized_page_whatever_entry_redirect_lists(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->get('/terms')->assertRedirect('/fr/terms');
        $this->get('/')->assertRedirect('/fr');
    }

    /** @return iterable<string, array{string, string, array<string, string>, string}> method, URI, headers, the language picked */
    public static function staysPut(): iterable
    {
        yield 'a POST' => ['POST', '/', [], 'fr'];
        yield 'a QUERY, which Symfony 7.4+ counts as cacheable' => ['QUERY', '/', [], 'fr'];
        yield 'a signed URL' => ['GET', '/?signature=x', [], 'fr'];
        yield 'a click inside the site' => ['GET', '/', ['Sec-Fetch-Site' => 'same-origin'], 'fr'];
        yield 'a click inside the site, by Referer' => ['GET', '/', ['Referer' => 'http://localhost/fr/terms'], 'fr'];
        yield 'Googlebot' => ['GET', '/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'], 'fr'];
        yield 'the default language picked' => ['GET', '/', [], 'en'];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('staysPut')]
    public function test_it_stays_put(string $method, string $uri, array $headers, string $picked): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => $picked]);

        // call() ignores withHeaders().
        $this->call($method, $uri, server: $this->transformHeadersToServerVars($headers))->assertOk()->assertContent('en');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function arrivals(): iterable
    {
        yield 'from another site' => [['Sec-Fetch-Site' => 'cross-site']];
        yield 'typed or bookmarked' => [['Sec-Fetch-Site' => 'none']];
        yield 'no Fetch Metadata, a search engine as Referer' => [['Referer' => 'https://www.google.com/']];
        yield 'an empty user agent, which is no crawler' => [['User-Agent' => '']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('arrivals')]
    public function test_an_arrival_is_redirected(array $headers): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->withHeaders($headers)->get('/')->assertRedirect('/fr');
    }

    public function test_typing_the_defaults_prefix_opens_the_default_copy_and_the_entry_redirect_leaves_it(): void
    {
        $arrival = ['Accept-Language' => 'fr', 'Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'];

        // A pick is known and redirects a bare copy; /en asked for this one by name, for its landing only.
        $this->withSession([ResolveLocale::PICKED_KEY => 'fr'])->withHeaders($arrival)->get('/en')->assertStatus(301)
            ->assertHeader('Location', 'http://localhost')->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertSame('fr', session(ResolveLocale::PICKED_KEY));
        $this->withHeaders($arrival)->get('/')->assertOk()->assertContent('en');
        $this->withHeaders($arrival)->get('/')->assertRedirect('/fr');

        $this->flushSession();
        $this->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'])->get('/en/terms')->assertStatus(301);
        $this->assertNull(session('localization'));
    }

    public function test_a_prefixed_copy_is_never_redirected(): void
    {
        $this->withSession([ResolveLocale::PICKED_KEY => 'ar'])->get('/es')->assertOk()->assertContent('es');
    }

    private function assertARevokedSessionWritesNothing(): void
    {
        // Explicit null, else ApplyLocale has nothing to fill.
        $member = User::create(['name' => 'member', 'password' => 'hash-now', 'locale' => null]);

        $this->actingAs($member)->withSession(['password_hash_web' => 'stale', ResolveLocale::PICKED_KEY => 'es'])->get('/plain');

        $this->assertNull($member->fresh()?->locale);
    }

    private function withAuthenticateSession(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', AuthenticateSession::class);
    }

    private function recallerName(): string
    {
        $guard = Auth::guard('web');
        assert($guard instanceof SessionGuard);

        return $guard->getRecallerName();
    }

    private function assertGuestNextTime(): void
    {
        $this->defaultCookies = [];
        Auth::forgetGuards();

        $this->get('/plain');

        $this->assertGuest();
    }
}
