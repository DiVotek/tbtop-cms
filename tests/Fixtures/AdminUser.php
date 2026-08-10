<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AdminUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
