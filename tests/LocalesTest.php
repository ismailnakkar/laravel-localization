<?php

declare(strict_types=1);

namespace Localization\Tests;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Localization\Locales;
use PHPUnit\Framework\Attributes\DataProvider;

final class LocalesTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, ?string}> codes, Accept-Language, expected */
    public static function browsers(): iterable
    {
        yield 'exact' => [['en', 'fr'], 'fr', 'fr'];
        yield 'a region falls back to its language' => [['en', 'fr'], 'fr-CA,en;q=0.5', 'fr'];
        yield 'the first language wins over a later exact match' => [['en', 'fr'], 'fr-CA,en;q=0.9', 'fr'];
        yield 'a configured region, any case' => [['en-GB', 'fr'], 'en-gb', 'en-GB'];
        yield 'a script code' => [['en', 'zh-Hant'], 'zh-Hant', 'zh-Hant'];
        yield 'a script code by its language' => [['en', 'zh-Hant'], 'zh-TW', 'zh-Hant'];
        yield 'the bare code before a regional one' => [['en', 'pt-BR', 'pt'], 'pt', 'pt'];
        yield 'a regional request, the bare code configured' => [['en', 'pt-BR', 'pt'], 'pt-PT', 'pt'];
        yield 'nothing matches' => [['en', 'fr'], 'de-DE,de', null];
        yield 'a wildcard' => [['en', 'fr'], '*', null];
        yield 'no header' => [['en', 'fr'], '', null];
    }

    /** @param list<string> $codes */
    #[DataProvider('browsers')]
    public function test_the_browsers_first_language_that_matches_wins(array $codes, string $header, ?string $expected): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

        $this->assertSame($expected, new Locales($codes, $codes[0])->preferredBy($request));
    }

    public function test_a_malformed_header_never_throws(): void
    {
        $locales = new Locales(['en', 'fr'], 'en');

        foreach ([';;;,,=', 'q=0', "\x00fr", str_repeat('fr-', 5000)] as $header) {
            $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

            $this->assertContains($locales->preferredBy($request), [null, 'en', 'fr']);
        }
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function badLocales(): iterable
    {
        yield 'a language name' => [['en', 'english'], 'en'];
        yield 'a bare region' => [['en', '-GB'], 'en'];
        yield 'upper-case language' => [['EN'], 'EN'];
        yield 'lower-case region' => [['en', 'en-gb'], 'en'];
        yield 'not a string' => [['en', 2], 'en'];
        yield 'a duplicate' => [['en', 'fr', 'en'], 'en'];
        yield 'no codes' => [[], 'en'];
        yield 'a default not in codes' => [['en', 'fr'], 'de'];
        yield 'a code with a trailing newline' => [['en', "fr\n"], 'en'];
        yield 'lower-case script' => [['zh', 'zh-hant'], 'zh'];
        yield 'a script after the region' => [['zh', 'zh-TW-Hant'], 'zh'];
        yield 'x-default' => [['en', 'x-default'], 'en'];
    }

    /** @param list<mixed> $codes */
    #[DataProvider('badLocales')]
    public function test_malformed_locales_throw(array $codes, string $default): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('localization.locales: ');

        new Locales($codes, $default);
    }
}
