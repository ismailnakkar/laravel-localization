<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Localization\Http\RedirectLegacyQuery;
use Localization\Http\ResolveLocale;
use PHPUnit\Framework\Attributes\DataProvider;

final class LegacyQueryTest extends LanguagesTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('localization.locales', ['en', 'fr', 'zh-Hant']);
    }

    protected function defineWebRoutes($router): void
    {
        $locale = static fn (): string => app()->getLocale();

        $router->localized(static fn (Router $router) => $router->match(['GET', 'HEAD', 'POST'], 'terms', $locale));
        $router->get('plain', $locale);
    }

    public function test_it_heads_web_and_stays_off_until_a_parameter_is_set_even_after_boot(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);
        $this->assertSame(RedirectLegacyQuery::class, $kernel->getMiddlewareGroups()['web'][0]);

        $this->get('/terms?lang=fr')->assertOk()->assertContent('en');

        config(['localization.legacy_query' => 'lang']);

        $this->get('/terms?lang=fr')->assertMovedPermanently()->assertRedirect('/fr/terms');
    }

    public function test_it_answers_before_the_session_starts_and_before_the_entry_redirect(): void
    {
        config(['localization.legacy_query' => 'lang']);

        // From outside, with a choice kept: ApplyLocale alone would send this to /zh-Hant/terms?lang=fr.
        $this->withCookie(ResolveLocale::COOKIE, 'zh-Hant')->get('/terms?lang=fr')
            ->assertMovedPermanently()
            ->assertRedirect('/fr/terms')
            ->assertCookieMissing((string)config('session.cookie'));
    }

    /** @return iterable<string, array{string, string}> URL, the copy it moves to */
    public static function languages(): iterable
    {
        yield 'a code' => ['/terms?lang=fr', '/fr/terms'];
        yield 'any case' => ['/terms?lang=FR', '/fr/terms'];
        yield 'a region, as browsers spell it' => ['/terms?lang=fr-FR', '/fr/terms'];
        yield 'a region, as POSIX spells it' => ['/terms?lang=fr_FR', '/fr/terms'];
        yield 'a script code' => ['/terms?lang=zh-Hant', '/zh-Hant/terms'];
        yield 'a script code by its language' => ['/terms?lang=zh_TW', '/zh-Hant/terms'];
        yield 'the default, from another copy' => ['/fr/terms?lang=en_US', '/terms'];
        yield 'the copy it is on' => ['/fr/terms?lang=fr', '/fr/terms'];
    }

    #[DataProvider('languages')]
    public function test_a_value_naming_a_configured_language_moves_for_good_to_its_copy(string $url, string $copy): void
    {
        config(['localization.legacy_query' => 'lang']);

        $this->get($url)->assertMovedPermanently()->assertRedirect($copy);
    }

    public function test_a_get_or_head_keeps_every_other_parameter_as_spelt(): void
    {
        config(['localization.legacy_query' => 'lang']);

        $this->get('/terms?utm.source=mail&lang=fr&q=a+b')->assertMovedPermanently()->assertRedirect('/fr/terms?utm.source=mail&q=a+b');
        $this->call('HEAD', '/terms?lang=fr&page=2')->assertMovedPermanently()->assertRedirect('/fr/terms?page=2');
    }

    public function test_it_drops_every_spelling_php_reads_as_the_parameter(): void
    {
        config(['localization.legacy_query' => 'lang']);

        $this->get('/fr/terms?+lang=fr')->assertMovedPermanently()->assertRedirect('/fr/terms');
        $this->get('/fr/terms?%20lang=fr&page=2')->assertMovedPermanently()->assertRedirect('/fr/terms?page=2');
        $this->get('/terms?lang%00=fr&la%6Eg=fr&b=1')->assertMovedPermanently()->assertRedirect('/fr/terms?b=1');
    }

    /** @return iterable<string, array{string, string}> method, URL */
    public static function untouched(): iterable
    {
        yield 'an unknown language' => ['GET', '/terms?lang=de'];
        yield 'an empty value' => ['GET', '/terms?lang='];
        yield 'a list' => ['GET', '/terms?lang[]=fr'];
        yield 'another parameter' => ['GET', '/terms?locale=fr'];
        yield 'a signed URL' => ['GET', '/terms?lang=fr&expires=1&signature=abc'];
        yield 'a page outside Route::localized()' => ['GET', '/plain?lang=fr'];
        yield 'a POST' => ['POST', '/terms?lang=fr'];
    }

    #[DataProvider('untouched')]
    public function test_anything_else_passes_through(string $method, string $url): void
    {
        config(['localization.legacy_query' => 'lang']);

        $this->call($method, $url)->assertOk()->assertContent('en');
    }
}
