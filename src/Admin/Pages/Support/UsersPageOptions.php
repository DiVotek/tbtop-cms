<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Admin\Enums\CmsRole;

/**
 * Role select options for the grant/change-role modals, scoped to what the
 * signed-in role may assign (CmsRole::assignableRoles()) — an administrator
 * building the form server-side never even sees "administrator" as an option.
 */
class UsersPageOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function assignableRoles(CmsRole $actingRole): array
    {
        return array_map(
            static fn (CmsRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            $actingRole->assignableRoles(),
        );
    }
}
