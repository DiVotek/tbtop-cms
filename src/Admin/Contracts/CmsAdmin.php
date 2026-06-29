<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Contracts;

interface CmsAdmin
{
    public function canAccessCmsAdmin(): bool;
}
