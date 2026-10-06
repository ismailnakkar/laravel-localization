<?php

declare(strict_types=1);

namespace Localization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class BoundaryTest extends TestCase
{
    /** laravel-seo reads the copies itself: nothing shipped names it, even in a string or docblock. */
    public function test_nothing_shipped_names_laravel_seo(): void
    {
        $root = dirname(__DIR__);
        $hits = [];

        foreach (Finder::create()->files()->in(["{$root}/src", "{$root}/config", "{$root}/routes"]) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match('/(?<![\w\\\\])\\\\?Seo\\\\|laravel-seo/i', $line) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, 'laravel-seo named in: ' . implode("\n", $hits));
    }
}
