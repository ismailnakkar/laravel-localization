<?php

declare(strict_types=1);

namespace Localization\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/** Siblings meet in the app through a hook: the app's code names them, the package's never does. */
final class BoundaryTest extends TestCase
{
    /** @return iterable<string, array{string}> the sibling's namespace */
    public static function siblings(): iterable
    {
        yield 'laravel-seo' => ['Seo'];
        yield 'laravel-accounts' => ['Accounts'];
    }

    #[DataProvider('siblings')]
    public function test_nothing_shipped_names_a_sibling(string $namespace): void
    {
        $root = dirname(__DIR__);
        $package = 'laravel-' . strtolower($namespace);
        $hits = [];

        foreach (Finder::create()->files()->in(["{$root}/src", "{$root}/config", "{$root}/routes"]) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match("/(?<![\\w\\\\])\\\\?{$namespace}\\\\|{$package}/i", $line) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, "{$package} named in: " . implode("\n", $hits));
    }
}
