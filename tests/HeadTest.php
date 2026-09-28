<?php

declare(strict_types=1);

namespace Localization\Tests;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use Seo\Page;
use Seo\Robots;

#[DefineEnvironment('withLanguagesAtBoot')]
final class HeadTest extends TestCase
{
    public function test_a_page_that_never_calls_page_is_indexable_by_default(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('/fr/reset-password?utm_source=x')
            ->assertOk()
            ->assertSee('<title>UpFiles</title>', false)
            ->assertSee('<meta name="robots" content="max-image-preview:large">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/fr/reset-password">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="http://localhost/reset-password">', false)
            ->assertSee('<meta property="og:url" content="http://localhost/fr/reset-password">', false)
            ->assertDontSee('name="description"', false);
    }

    public function test_a_noindex_page_has_no_canonical_hreflang_or_og_url(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('/fr/faq', new Page(title: 'FAQ', robots: Robots::noindex))
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang', false)
            ->assertDontSee('og:url', false);
    }

    public function test_an_indexable_page_on_a_noindex_host_renders_noindex_nofollow_and_no_canonical(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('http://dl.test/fr/faq', new Page(title: 'FAQ'))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang', false)
            ->assertDontSee('og:url', false);
    }

    public function test_the_hreflang_block_renders_in_emission_order(): void
    {
        $this->withSite(['name' => 'cuty.io', 'title_separator' => ' | ', 'image' => '/og.png']);
        $this->withLocales(['en', 'fr']);

        $this->visit('/fr/faq', new Page(title: "Conditions d'utilisation", description: 'Desc.'))->assertSeeInOrder([
            '<html lang="fr">',
            '<title>Conditions d&#039;utilisation | cuty.io</title>',
            '<meta name="description" content="Desc.">',
            '<meta name="robots" content="max-image-preview:large">',
            '<link rel="canonical" href="http://localhost/fr/faq">',
            '<link rel="alternate" hreflang="en" href="http://localhost/faq">',
            '<link rel="alternate" hreflang="fr" href="http://localhost/fr/faq">',
            '<link rel="alternate" hreflang="x-default" href="http://localhost/faq">',
            '<meta property="og:site_name" content="cuty.io">',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Conditions d&#039;utilisation | cuty.io">',
            '<meta property="og:description" content="Desc.">',
            '<meta property="og:url" content="http://localhost/fr/faq">',
            '<meta property="og:image" content="http://localhost/og.png">',
            '<meta property="og:image:alt" content="cuty.io">',
            '<meta name="twitter:card" content="summary_large_image">',
        ], false);
    }
}
