<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class Admin extends Authenticatable
{
    protected $guarded = [];
}
