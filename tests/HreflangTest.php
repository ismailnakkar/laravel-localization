<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Seo\Page;
use Seo\ParsedPage;
use Seo\Robots;
use Seo\Testing\SeoAssertions;

/** laravel-seo's canonical and hreflang on real Route::localized() routes, through the seam. */
#[DefineEnvironment('withLanguagesAtBoot')]
final class HreflangTest extends TestCase
{
    use SeoAssertions;

    public function test_every_locale_url_emits_the_identical_set_in_codes_order_with_x_default_last(): void
    {
        $this->withSite();
        $this->withLocales();

        $expected = [
            'en'        => 'http://localhost/faq',
            'fr'        => 'http://localhost/fr/faq',
            'ar'        => 'http://localhost/ar/faq',
            'es'        => 'http://localhost/es/faq',
            'x-default' => 'http://localhost/faq',
        ];

        foreach (['/faq', '/fr/faq', '/ar/faq', '/es/faq', '/fr/faq/', '/faq?lang=fr', 'http://go.test/es/faq?utm_source=x'] as $url) {
            $this->assertSame($expected, $this->alternates($url, new Page(title: 'FAQ')), $url);
        }

        $expected = [
            'en'        => 'http://localhost/',
            'fr'        => 'http://localhost/fr',
            'ar'        => 'http://localhost/ar',
            'es'        => 'http://localhost/es',
            'x-default' => 'http://localhost/',
        ];

        foreach (['/', '/fr', '/ar/', '/es'] as $url) {
            $this->assertSame($expected, $this->alternates($url, new Page(title: 'Home')), $url);
        }
    }

    public function test_an_encoded_locale_prefix_emits_its_plain_spellings_set_and_canonical(): void
    {
        // The router matches the rawurldecoded path, so these reach the fr copy.
        $this->withSite();
        $this->withLocales();

        foreach (['/%66r/faq' => '/faq', '/fr%2Ffaq' => '/faq', '/fr%2ffaq/' => '/faq', '/%66r' => ''] as $url => $path) {
            $page = new Page(title: 'FAQ');
            $expected = [
                'en'        => 'http://localhost' . ($path ?: '/'),
                'fr'        => "http://localhost/fr{$path}",
                'ar'        => "http://localhost/ar{$path}",
                'es'        => "http://localhost/es{$path}",
                'x-default' => 'http://localhost' . ($path ?: '/'),
            ];

            $this->assertSame($expected, $this->alternates($url, $page), $url);
            $this->assertSame("http://localhost/fr{$path}", $this->canonicalOf($url, $page), $url);
        }
    }

    public function test_a_catch_all_path_holding_a_url_or_a_host_keeps_its_canonical_and_set_on_the_site(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(['en', 'fr'], function (Router $router): void {
            $router->get('{path}', $this->renderFixturePage(...))->where('path', '.*');
        });
        $expected = [
            'en'        => 'http://localhost/x/https://y.test',
            'fr'        => 'http://localhost/fr/x/https://y.test',
            'x-default' => 'http://localhost/x/https://y.test',
        ];

        foreach (['/fr/x/https://y.test', '/x/https://y.test'] as $url) {
            $page = new Page(title: 'X');

            $this->assertSame($expected, $this->alternates($url, $page), $url);
            $this->assertSame("http://localhost{$url}", $this->canonicalOf($url, $page), $url);
        }

        // Stripping the fr prefix leaves `//evil.test/x`, which a browser reads as a host.
        $this->assertSame([
            'en'        => 'http://localhost/evil.test/x',
            'fr'        => 'http://localhost/fr//evil.test/x',
            'x-default' => 'http://localhost/evil.test/x',
        ], $this->alternates('/fr//evil.test/x', new Page(title: 'X')));
    }

    public function test_each_alternate_is_self_canonical_including_the_paginated_combination(): void
    {
        $this->withSite();
        $this->withLocales();

        foreach (['/ar/faq' => new Page(title: 'FAQ'), '/fr' => new Page(title: 'Home'), '/fr/payment-proof?page=2&utm_source=x' => new Page(title: 'Payment proof', paginated: true)] as $url => $page) {
            foreach ($this->alternates($url, $page) as $hreflang => $href) {
                $this->assertSame($href, $this->canonicalOf($href, $page), "{$hreflang} {$href}");
            }
        }

        $this->assertSame([
            'en'        => 'http://localhost/payment-proof?page=2',
            'fr'        => 'http://localhost/fr/payment-proof?page=2',
            'ar'        => 'http://localhost/ar/payment-proof?page=2',
            'es'        => 'http://localhost/es/payment-proof?page=2',
            'x-default' => 'http://localhost/payment-proof?page=2',
        ], $this->alternates('/fr/payment-proof?page=2&utm_source=x', new Page(title: 'Payment proof', paginated: true)));
    }

    public function test_there_are_no_alternates_outside_route_localized_with_an_override_or_on_a_noindex_page(): void
    {
        $site = $this->withSite();
        $this->assertSame([], $this->alternates('/faq', new Page(title: 'FAQ')));
        $this->assertSame([], $site->alternates(Request::create('/fr/faq')));

        $this->withLocales();
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', canonical: 'https://short.test/x')));
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', robots: Robots::none)));
    }

    public function test_script_and_region_codes_are_path_segments_and_hreflang_values(): void
    {
        // Google documents script subtags (zh-Hans, zh-Hant), optionally with a region.
        $this->withSite();
        $this->withLocales(['en-GB', 'zh-Hant', 'zh-Hant-TW']);

        $this->assertSame([
            'en-GB'      => 'http://localhost/faq',
            'zh-Hant'    => 'http://localhost/zh-Hant/faq',
            'zh-Hant-TW' => 'http://localhost/zh-Hant-TW/faq',
            'x-default'  => 'http://localhost/faq',
        ], $this->alternates('/zh-Hant-TW/faq', new Page(title: 'FAQ')));
    }

    public function test_assert_hreflang_reciprocal_passes_on_path_locales(): void
    {
        $this->withSite();
        $this->withLocales();
        $this->fixturePage = static fn (): Page => new Page(title: 'Listing', paginated: true);

        $this->assertHreflangReciprocal('/faq');
        $this->assertHreflangReciprocal('/ar/faq');
        $this->assertHreflangReciprocal('/fr');
        $this->assertHreflangReciprocal('/fr/payment-proof?page=2');
    }

    public function test_the_set_canonical_and_sitemap_survive_route_cache(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Seo\Seo;

            // route:cache runs in a subprocess with a fresh config: the codes are set here.
            config(['localization.locales' => ['en', 'fr', 'ar']]);

            Route::middleware('web')->group(static function (): void {
                Route::localized(static function (): void {
                    Route::get('faq', static function (Seo $seo) {
                        $seo->page(title: 'FAQ');

                        return view('page', ['lang' => app()->getLocale()]);
                    });
                });
            });
            PHP);
        $this->withSite();

        $this->assertTrue($this->app->routesAreCached());
        $this->assertSame([
            'en'        => 'http://localhost/faq',
            'fr'        => 'http://localhost/fr/faq',
            'ar'        => 'http://localhost/ar/faq',
            'x-default' => 'http://localhost/faq',
        ], $this->alternates('/%61r/faq'));
        $this->assertSame('http://localhost/ar/faq', $this->canonicalOf('/%61r/faq'));

        $this->withSitemap(['/fr/faq']);
        $this->assertSame(
            ['http://localhost/faq', 'http://localhost/fr/faq', 'http://localhost/ar/faq'],
            $this->locs(),
        );
    }

    /** @return array<string, string> hreflang => href */
    private function alternates(string $url, ?Page $page = null): array
    {
        return ParsedPage::parse((string)$this->visit($url, $page)->assertOk()->getContent())->alternates;
    }

    private function canonicalOf(string $url, ?Page $page = null): ?string
    {
        return ParsedPage::parse((string)$this->visit($url, $page)->getContent())->canonicals[0] ?? null;
    }
}
