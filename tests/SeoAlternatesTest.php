<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Localization\SeoAlternates;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Seo\Seo;

final class SeoAlternatesTest extends TestCase
{
    /** For #[DefineEnvironment]: resolves Seo before the providers boot, as an earlier provider's boot() might. */
    protected function resolvingSeoFirst(Application $app): void
    {
        $app->make(Seo::class);
    }

    #[DefineEnvironment('withLanguagesAtBoot')]
    public function test_it_answers_once_seo_is_resolved_after_the_provider_booted(): void
    {
        $this->assertTheCopiesExpand();
    }

    #[DefineEnvironment('withLanguagesAtBoot')]
    #[DefineEnvironment('resolvingSeoFirst')]
    public function test_it_answers_when_seo_was_resolved_before_the_provider_booted(): void
    {
        $this->assertTheCopiesExpand();
    }

    public function test_below_two_locales_at_boot_nothing_is_registered(): void
    {
        // Localized after boot, so a registered closure would still expand /fr/faq.
        $this->withSite();
        $this->withLocales(['en', 'fr']);
        $this->withSitemap(['/fr/faq']);

        $this->assertSame(['http://localhost/fr/faq'], $this->locs());
    }

    #[DefineEnvironment('withLanguagesAtBoot')]
    public function test_it_answers_with_the_copys_own_path_and_every_codes_in_order_and_null_off_route_localized(): void
    {
        $this->withLocales(['en', 'fr', 'ar']);
        Route::get('plain', static fn () => 'plain');

        $this->assertSame(['path' => '/fr/faq', 'alternates' => ['en' => '/faq', 'fr' => '/fr/faq', 'ar' => '/ar/faq']], $this->answer('/fr/faq'));
        $this->assertSame(['path' => '/ar/faq', 'alternates' => ['en' => '/faq', 'fr' => '/fr/faq', 'ar' => '/ar/faq']], $this->answer('/%61r/faq'));
        $this->assertSame(['path' => '/', 'alternates' => ['en' => '/', 'fr' => '/fr', 'ar' => '/ar']], $this->answer('/'));
        $this->assertNull($this->answer('/plain'));
    }

    private function assertTheCopiesExpand(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr']);
        $this->withSitemap(['/fr/faq']);

        $this->assertSame(['http://localhost/faq', 'http://localhost/fr/faq'], $this->locs());
    }

    /** @return array{path: string, alternates: array<string, string>}|null */
    private function answer(string $path): ?array
    {
        return SeoAlternates::answer(Route::getRoutes()->match(Request::create($path)), $path);
    }
}
