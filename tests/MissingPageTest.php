<?php

declare(strict_types=1);

namespace Localization\Tests;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Localization\Http\ResolveLocale;
use Localization\MissingPage;
use Localization\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MissingPageTest extends LanguagesTestCase
{
    /** The page every case renders: an app's own errors/404.blade.php, which prints the locale and a translated line. */
    private const array PAGES = [
        '/fr/x'   => '<html lang="fr">Page introuvable</html>',
        '/ar/x/y' => '<html lang="ar">الصفحة غير موجودة</html>',
        '/es'     => '<html lang="es">Not Found</html>',
        '/xx/x'   => '<html lang="en">Not Found</html>',
        '/x'      => '<html lang="en">Not Found</html>',
        '/en/x'   => '<html lang="en">Not Found</html>',
        '/FR/x'   => '<html lang="en">Not Found</html>',
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $translator = $this->app->make('translator');
        $translator->addLines(['*.Not Found' => 'Page introuvable'], 'fr');
        $translator->addLines(['*.Not Found' => 'الصفحة غير موجودة'], 'ar');
        $this->withLocalizedRoutes(['en', 'fr', 'ar', 'es'], static fn () => Route::get('terms', static fn (): string => app()->getLocale()));
    }

    /** @return iterable<string, array{Closure(): mixed, string, string}> how the app's routes end, a path, its page */
    public static function missingPages(): iterable
    {
        $endings = [
            'no fallback'            => static function (): void {},
            'a fallback in web'      => static fn () => Route::middleware('web')->group(static fn () => Route::fallback(static fn () => abort(404))),
            'a fallback without web' => static fn () => Route::fallback(static fn () => abort(404)),
        ];

        foreach ($endings as $ending => $routes) {
            foreach (self::PAGES as $path => $page) {
                yield "{$ending}, {$path}" => [$routes, $path, $page];
            }
        }
    }

    #[DataProvider('missingPages')]
    public function test_a_path_no_route_matches_404s_in_its_prefixs_language(Closure $routes, string $path, string $page): void
    {
        $routes();

        $this->assertSame($page, trim((string)$this->get($path)->assertNotFound()->getContent()));
    }

    public function test_an_exception_handlers_own_404_renders_in_it(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        assert($handler instanceof Handler);
        $handler->renderable(static fn (NotFoundHttpException $e) => response('handler:' . app()->getLocale(), 404));

        $this->fresh()->get('/fr/x')->assertNotFound()->assertContent('handler:fr');
        $this->fresh()->get('/xx/x')->assertNotFound()->assertContent('handler:en');
        $this->fresh()->get('/x')->assertNotFound()->assertContent('handler:en');
    }

    public function test_only_a_prefix_outranks_the_visitors_language_on_a_fallback_in_web(): void
    {
        Route::middleware('web')->group(static fn () => Route::fallback(static fn (): string => 'fallback:' . app()->getLocale()));

        foreach (['/fr/x' => 'fallback:fr', '/x' => 'fallback:ar', '/en/x' => 'fallback:ar', '/xx/x' => 'fallback:ar'] as $path => $content) {
            $this->fresh()->withSession([ResolveLocale::PICKED_KEY => 'ar'])->get($path)->assertOk()->assertContent($content);
        }
    }

    public function test_an_account_without_a_language_takes_a_missing_pages_on_a_fallback_in_web(): void
    {
        Route::middleware('web')->group(static fn () => Route::fallback(static fn () => abort(404)));
        // Explicit null: only a loaded, empty column is filled.
        $user = User::create(['name' => 'member', 'locale' => null]);

        $this->actingAs($user)->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/fr/x')->assertNotFound();

        $this->assertSame('fr', $user->fresh()?->locale);
    }

    public function test_a_matched_route_keeps_its_language(): void
    {
        Route::get('fr/plain', static fn (): string => app()->getLocale());
        Route::middleware('web')->get('ar/plain', static fn (): string => app()->getLocale());
        Route::fallback(static fn () => abort(404));

        $this->fresh()->get('/fr/terms')->assertOk()->assertContent('fr');
        $this->fresh()->get('/terms')->assertOk()->assertContent('en');
        $this->fresh()->get('/fr/plain')->assertOk()->assertContent('en');
        $this->fresh()->withSession([ResolveLocale::PICKED_KEY => 'es'])->get('/ar/plain')->assertOk()->assertContent('es');
    }

    public function test_it_holds_on_compiled_routes(): void
    {
        Route::get('fr/plain', static fn (): string => app()->getLocale());
        Route::middleware('web')->group(static fn () => Route::fallback(static fn () => abort(404)));
        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();
        assert($routes instanceof RouteCollection);
        $router->setCompiledRoutes($routes->compile());

        $this->fresh()->get('/fr/plain')->assertOk()->assertContent('en');
        $this->fresh()->get('/fr/terms')->assertOk()->assertContent('fr');

        foreach (self::PAGES as $path => $page) {
            $this->assertSame($page, trim((string)$this->fresh()->get($path)->assertNotFound()->getContent()), $path);
        }
    }

    public function test_below_two_locales_nothing_changes(): void
    {
        config(['localization.locales' => ['en']]);

        $this->get('/fr/x')->assertNotFound()->assertSee('<html lang="en">', false);
    }

    public function test_the_listeners_are_class_callables(): void
    {
        Event::fake();
        Event::assertListening(Routing::class, [MissingPage::class, 'routing']);
        Event::assertListening(RouteMatched::class, [MissingPage::class, 'matched']);
    }

    /** A real request starts in app.locale; the test app keeps the previous request's. */
    private function fresh(): self
    {
        $this->app->setLocale('en');

        return $this;
    }
}
