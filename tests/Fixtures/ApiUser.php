<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Laravel\Sanctum\HasApiTokens;

/** A host user model set up for the pages API, as a host's User is after installing Sanctum. */
class ApiUser extends AdminUser
{
    use HasApiTokens;
}
