<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Uses both traits to reproduce, on any Laravel version, the
 * class_uses_recursive() shape that early Laravel 11 minors produced for a
 * HasUlids host (where HasUlids itself `use HasUuids`) — see UserKeyColumn.
 */
class UlidAdminUser extends Authenticatable
{
    use HasUlids, HasUuids {
        HasUlids::newUniqueId insteadof HasUuids;
        HasUlids::uniqueIds insteadof HasUuids;
        HasUlids::isValidUniqueId insteadof HasUuids;
    }

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
