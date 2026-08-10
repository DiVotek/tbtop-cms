<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;

/**
 * Contract admin whose access depends on the row itself (email domain),
 * unlike ContractAdminUser's blanket true — lets tests assert that fan-out
 * enumeration keeps contract-admitted users and drops contract-denied ones.
 */
class GatedContractAdminUser extends Authenticatable implements CmsAdmin
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];

    public function canAccessCmsAdmin(): bool
    {
        return str_ends_with((string) $this->email, '@admins.test');
    }
}
