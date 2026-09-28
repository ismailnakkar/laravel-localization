<?php

declare(strict_types=1);

namespace Localization\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property mixed $locale
 */
final class User extends Authenticatable
{
    protected $guarded = [];
}
