<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A second guard's model; its table has no `locale` column. */
final class Admin extends Authenticatable
{
    protected $guarded = [];
}
