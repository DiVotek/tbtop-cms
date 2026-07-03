<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;

class ContractAdminUser extends Authenticatable implements CmsAdmin
{
    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];

    public function canAccessCmsAdmin(): bool
    {
        return true;
    }
}
