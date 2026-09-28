<?php

declare(strict_types=1);

namespace Localization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class BoundaryTest extends TestCase
{
    /**
     * laravel-seo is optional, so only SeoAlternates may name it, even in class_exists(), a string or a docblock.
     * `Localization\Seo…` does not count.
     */
    public function test_only_seo_alternates_names_laravel_seo(): void
    {
        $root = dirname(__DIR__);
        $hits = [];

        foreach (Finder::create()->files()->in("{$root}/src")->notName('SeoAlternates.php') as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match('/(?<![\w\\\\])\\\\?Seo\\\\/', $line) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, 'Seo\ named outside SeoAlternates.php in: ' . implode("\n", $hits));
    }
}
