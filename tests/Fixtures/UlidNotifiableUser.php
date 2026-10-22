<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A host user keyed by ULIDs that receives bell notifications. The suite's own
 * `users` table is integer-keyed, so a test using this model creates
 * `ulid_users` itself.
 */
class UlidNotifiableUser extends Authenticatable
{
    use HasUlids;
    use Notifiable;

    protected $table = 'ulid_users';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
