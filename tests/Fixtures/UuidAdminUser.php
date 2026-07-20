<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class UuidAdminUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
