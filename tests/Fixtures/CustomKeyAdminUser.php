<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A host user model whose primary key column is not `id`. */
class CustomKeyAdminUser extends Authenticatable
{
    protected $table = 'custom_key_users';

    protected $primaryKey = 'uid';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
