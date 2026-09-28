<?php

declare(strict_types=1);

namespace Localization\Tests;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Localization\Localization;
use Localization\LocalizationServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Seo\Page;
use Seo\Seo;
use Seo\SeoServiceProvider;
use Seo\Site;
use Seo\SitemapEntry;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    protected const string URL = 'http://localhost';

    protected const array PAGES = ['/', '/faq', '/payment-proof', '/reset-password'];

    /** @var (Closure(Request): ?Page)|null null sets no Page */
    protected ?Closure $fixturePage = null;

    protected function setUp(): void
    {
        // Laravel 12 keeps this static across tests.
        PreventRequestsDuringMaintenance::flushState();

        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [SeoServiceProvider::class, LocalizationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::URL);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32))); // EncryptCookies needs it
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
        $app['config']->set('database.default', 'testing');
    }

    /** For #[DefineEnvironment]: laravel-seo's closure registers only if languages are set at boot. */
    protected function withLanguagesAtBoot(Application $app): void
    {
        $app['config']->set('localization.locales', ['en', 'fr']);
    }

    protected function defineWebRoutes($router): void
    {
        foreach (static::PAGES as $path) {
            $router->get($path, $this->renderFixturePage(...));
        }
    }

    /** Relative URLs hit the index host, not the previous request's. */
    protected function prepareUrlForRequest($uri)
    {
        return is_string($uri) && str_starts_with($uri, '/') ? self::URL . $uri : parent::prepareUrlForRequest($uri);
    }

    protected function renderFixturePage(Request $request, Seo $seo): View
    {
        $page = $this->fixturePage === null ? null : ($this->fixturePage)($request);

        if ($page !== null) {
            $seo->page(...get_object_vars($page));
        }

        return view('page', ['lang' => $this->app->getLocale()]);
    }

    /** @return TestResponse<Response> */
    protected function visit(string $url, ?Page $page = null): TestResponse
    {
        $this->fixturePage = $page === null ? null : static fn (): Page => $page;

        return $this->get($url);
    }

    protected function seo(): Seo
    {
        return $this->app->make(Seo::class);
    }

    protected function localization(): Localization
    {
        return $this->app->make(Localization::class);
    }

    /** @param  array<string, mixed>  $config  seo.* keys */
    protected function withSite(array $config = []): Site
    {
        config(['seo' => [
            'name'          => 'UpFiles',
            'image'         => '/img/og-image.png',
            'disallow'      => ['/admin/'],
            'noindex_hosts' => ['dl.test'],
            ...$config,
        ] + config('seo')]);

        return $this->seo()->site();
    }

    /** @param list<SitemapEntry|string> $entries */
    protected function withSitemap(array $entries): void
    {
        $entries = array_map(static fn (SitemapEntry|string $entry): SitemapEntry => is_string($entry) ? new SitemapEntry($entry) : $entry, $entries);

        $this->seo()->sitemapUsing(static fn (): array => $entries);
    }

    /** @return list<string> */
    protected function locs(): array
    {
        return array_column(iterator_to_array($this->seo()->sitemap(), false), 'loc');
    }

    /** @param  list<string>  $codes  the first is the default */
    protected function withLocales(array $codes = ['en', 'fr', 'ar', 'es']): void
    {
        $this->withLocalizedRoutes($codes, self::defineWebRoutes(...));
    }

    /** @param list<string> $codes the first is the default */
    protected function withLocalizedRoutes(array $codes, Closure $routes): void
    {
        config(['localization.locales' => $codes]);
        $router = $this->app->make(Router::class);
        $router->middleware('web')->group(static fn (Router $router) => $router->localized($routes));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    protected function createUsersTable(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('');
            $table->string('locale', 20)->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createAdminsTable(): void
    {
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
}
