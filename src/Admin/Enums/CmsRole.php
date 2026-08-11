<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Enums;

/**
 * The three cms admin roles (audit item 4.1). Backed by cms_user_profiles.role.
 *
 * Section gates (tbtop-cms.pages, tbtop-cms.media, ...) are defined in
 * CmsServiceProvider; sections() here is the single source of truth for
 * which abilities each role passes, so the gate closures and any future
 * matrix consumer read from one place.
 */
enum CmsRole: string
{
    case SuperAdministrator = 'superadministrator';
    case Administrator = 'administrator';
    case Editor = 'editor';

    /**
     * Section gate abilities this role passes. Dashboard/profile are not
     * listed — those pages return null from can() (any signed-in cms user).
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return match ($this) {
            self::Editor => [
                'tbtop-cms.pages',
                'tbtop-cms.media',
            ],
            self::Administrator => [
                'tbtop-cms.pages',
                'tbtop-cms.media',
                'tbtop-cms.submissions',
                'tbtop-cms.redirects',
                'tbtop-cms.settings',
                'tbtop-cms.design',
                'tbtop-cms.languages',
                'tbtop-cms.users',
            ],
            self::SuperAdministrator => [
                'tbtop-cms.pages',
                'tbtop-cms.media',
                'tbtop-cms.submissions',
                'tbtop-cms.redirects',
                'tbtop-cms.settings',
                'tbtop-cms.design',
                'tbtop-cms.languages',
                'tbtop-cms.users',
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->sections(), true);
    }

    /**
     * Whether this role may manage a user currently holding $other's role
     * (grant/change-role/revoke). Superadministrator manages everyone
     * (including other superadministrators); administrator manages only
     * editors; editor manages nobody. Self-management is a separate rule
     * enforced by the caller (UsersPage), not encoded here.
     */
    public function canManage(self $other): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => $other === self::Editor,
            self::Editor => false,
        };
    }

    /**
     * Roles this role may assign to a user (grant or change-role target).
     * Superadministrator may assign any role; administrator may only ever
     * assign editor; editor may assign nothing.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SuperAdministrator => [self::SuperAdministrator, self::Administrator, self::Editor],
            self::Administrator => [self::Editor],
            self::Editor => [],
        };
    }

    public function label(): string
    {
        return __('tbtop-cms::users.roles.' . $this->value);
    }
}
