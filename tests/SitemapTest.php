<?php

declare(strict_types=1);

namespace Localization\Tests;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Seo\SitemapEntry;

/** laravel-seo's sitemap expansion on real Route::localized() routes, through the seam. */
#[DefineEnvironment('withLanguagesAtBoot')]
final class SitemapTest extends TestCase
{
    public function test_a_localized_loc_expands_to_every_locale_default_first_with_lastmod_copied_and_the_query_kept(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr', 'ar', 'es']);
        $lastModified = new DateTimeImmutable('2026-09-24T08:12:03+00:00');
        $this->withSitemap([new SitemapEntry('/faq', $lastModified), '/payment-proof?page=2&lang=de&v1.2=x', 'http://localhost']);

        $sitemap = iterator_to_array($this->seo()->sitemap(), false);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/fr/faq',
            'http://localhost/ar/faq',
            'http://localhost/es/faq',
            'http://localhost/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/fr/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/ar/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/es/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/',
            'http://localhost/fr',
            'http://localhost/ar',
            'http://localhost/es',
        ], array_column($sitemap, 'loc'));
        $this->assertSame([...array_fill(0, 4, $lastModified), ...array_fill(0, 8, null)], array_column($sitemap, 'lastModified'));
    }

    public function test_a_loc_on_any_copy_expands_to_the_same_set(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr', 'ar']);
        // The router decodes %61r to ar.
        $this->withSitemap(['/ar/faq', 'http://localhost/fr/', '/%61r/payment-proof']);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/fr/faq',
            'http://localhost/ar/faq',
            'http://localhost/',
            'http://localhost/fr',
            'http://localhost/ar',
            'http://localhost/payment-proof',
            'http://localhost/fr/payment-proof',
            'http://localhost/ar/payment-proof',
        ], $this->locs());
    }

    public function test_an_unlocalized_loc_and_one_that_matches_no_route_are_listed_once(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(['en', 'fr'], static fn () => Route::get('terms', static fn () => 'terms'));
        Route::post('contact', static fn () => 'sent');
        $this->withSitemap(['/faq', '/nowhere', '/fr/nowhere', '/contact']);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/nowhere',
            'http://localhost/fr/nowhere',
            'http://localhost/contact',
        ], $this->locs());
    }

    public function test_a_loc_an_earlier_unlocalized_route_serves_is_not_expanded_by_a_localized_catch_all(): void
    {
        $this->withSite();
        Route::get('pricing', static fn () => 'pricing');
        $this->withLocalizedRoutes(['en', 'fr'], static fn () => Route::get('{page}', static fn (string $page) => $page));
        $this->withSitemap(['/pricing', '/about']);

        $this->assertSame([
            'http://localhost/pricing',
            'http://localhost/about',
            'http://localhost/fr/about',
        ], $this->locs());
    }

    public function test_a_resolver_entry_on_another_copy_of_a_localized_config_loc_replaces_its_set(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr']);
        $lastModified = new DateTimeImmutable('2026-09-24T08:12:03+00:00');
        config(['seo.sitemap' => ['/faq']]);
        $this->withSitemap([new SitemapEntry('/fr/faq', $lastModified)]);

        $sitemap = iterator_to_array($this->seo()->sitemap(), false);

        $this->assertSame(['http://localhost/faq', 'http://localhost/fr/faq'], array_column($sitemap, 'loc'));
        $this->assertSame([$lastModified, $lastModified], array_column($sitemap, 'lastModified'));
    }

    public function test_a_config_loc_on_a_localized_route_expands_too(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(['en', 'fr'], static fn () => Route::get('terms', static fn () => 'terms')->name('terms'));
        $this->app->setLocale('fr');
        config(['seo.sitemap' => ['terms']]);

        $this->assertSame(['http://localhost/terms', 'http://localhost/fr/terms'], $this->locs());
    }

    public function test_building_the_sitemap_leaves_the_current_routes_parameters_alone(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(['en', 'fr'], function (): void {
            Route::get('blog/{post}', fn (Request $request): string => count(iterator_to_array($this->seo()->sitemap(), false)) . ' ' . $request->route('post'));
        });
        $this->withSitemap(['/blog/other', '/fr/blog/third']);

        $this->get('/blog/hello')->assertOk()->assertContent('4 hello');
    }
}
