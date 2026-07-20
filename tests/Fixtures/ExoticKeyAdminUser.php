<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class ExoticKeyAdminUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
