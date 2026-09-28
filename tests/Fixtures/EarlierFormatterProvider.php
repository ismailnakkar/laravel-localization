<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

final class EarlierFormatterProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(UrlGenerator::class)->formatPathUsing(static fn (string $path): string => str_replace('/legacy', '/renamed', $path));
    }
}
