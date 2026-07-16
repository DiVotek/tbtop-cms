<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\UsersPageOptions;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * Grants/revokes cms admin access and assigns roles for EXISTING host-app
 * users. Does not create users or manage passwords — that's the host app's
 * concern (see cms:admin:grant/revoke Artisan commands for the CLI
 * equivalent of grant/revoke, which this page duplicates as an admin-UI
 * surface with role assignment on top).
 *
 * Every mutation is re-checked server-side against CmsRole::canManage()/
 * assignableRoles() regardless of what the UI hid — hiddenIf only avoids
 * showing an action a request would reject anyway.
 */
class UsersPage extends AdminPage
{
    public static function path(): string
    {
        return 'users';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.users'),
            'order' => 20,
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return 'tbtop-cms.users';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::users.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::users.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.users')],
        ];
    }

    public function view(S $s): Node
    {
        $table = $s->table('users')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::users.columns.name'))->searchable()->sortable(),
                Column::make('email')->label(__('tbtop-cms::users.columns.email'))->searchable()->sortable(),
                // formatUsing runs on the projected value but hiddenIf()
                // reads it too, so hiddenIf compares against the label text,
                // not the raw role slug — see the grant/change-role/revoke
                // actions below, which key off cms_role's *formatted* value.
                Column::make('cms_role')->label(__('tbtop-cms::users.columns.access'))
                    ->formatUsing($this->roleLabel(...))
                    ->badge([
                        __('tbtop-cms::users.roles.superadministrator') => Color::Danger,
                        __('tbtop-cms::users.roles.administrator') => Color::Info,
                        __('tbtop-cms::users.roles.editor') => Color::Gray,
                        __('tbtop-cms::users.no_access') => Color::Gray,
                    ]),
                // Not shown by default — projected purely so hiddenIf() can
                // gate row actions client-side (mirrors PagesPage's
                // deleted_at). Raw SQL expressions, not a cast model
                // attribute, so formatUsing forces a real PHP bool onto the
                // wire — the driver would otherwise hand back 0/1 (sqlite)
                // and hiddenIf's eq($field, true/false) needs an actual bool.
                Column::make('is_self')->label('is_self')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
                Column::make('manageable')->label('manageable')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
            ])
            ->searchable(['name', 'email'])
            ->defaultSort('name', 'asc')
            ->emptyState(__('tbtop-cms::users.empty'))
            ->rowActions([
                $this->grantAction($s),
                $this->changeRoleAction($s),
                $this->revokeAction($s),
            ]);

        return $s->stack([$table]);
    }

    /**
     * One query, no N+1: cms_role comes from a correlated subselect over
     * cms_user_profiles (one row per user id, same shape as PagesPage's
     * parent_name subselect); is_self and manageable are computed the same
     * way so hiddenIf() on the row actions never needs a second pass.
     * manageable's allowed role set comes from CmsRole::canManage() — a PHP
     * match, not a portable cross-engine SQL CASE — evaluated once up front
     * for the acting role, then turned into a plain whereIn().
     *
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $model = $this->userModel();
        $table = (new $model)->getTable();
        $currentId = CurrentAdmin::user()?->getKey();
        $acting = CurrentAdmin::role();

        $manageableRoles = array_values(array_map(
            static fn (CmsRole $role): string => $role->value,
            array_filter(CmsRole::cases(), static fn (CmsRole $role): bool => $acting->canManage($role)),
        ));

        $query = $model::query()
            ->select($table . '.*')
            ->selectSub(
                CmsUserProfile::query()->select('role')
                    ->whereColumn('user_id', $table . '.id')
                    ->limit(1),
                'cms_role',
            )
            ->selectRaw($currentId !== null ? $table . '.id = ? as is_self' : '0 as is_self', $currentId !== null ? [$currentId] : []);

        if ($manageableRoles === []) {
            $query->selectRaw('0 as manageable');
        } else {
            $query->selectSub(
                CmsUserProfile::query()->selectRaw('1')
                    ->whereColumn('user_id', $table . '.id')
                    ->whereIn('role', $manageableRoles)
                    ->limit(1),
                'manageable',
            );
        }

        return $query;
    }

    private function roleLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return __('tbtop-cms::users.no_access');
        }

        $role = self::roleFromValue($value);

        return $role !== null ? $role->label() : __('tbtop-cms::users.no_access');
    }

    private function grantAction(S $s): ActionBuilder
    {
        $acting = CurrentAdmin::role();

        $cancel = $s->action('userGrantCancel')->label(__('tbtop-cms::users.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $save = $s->action('userGrantSave')->label(__('tbtop-cms::users.actions.grant'))->color('primary')
            ->handle($this->grant(...), needs: ['row', 'form']);

        $form = $s->form('userGrant', [
            $s->select('role')->label(__('tbtop-cms::users.fields.role'))->required()
                ->options(UsersPageOptions::assignableRoles($acting)),
            $s->flex([$s->spacer(), $cancel, $save], justify: 'end'),
        ]);

        // cms_role is the badge column: formatUsing already turned a null
        // role into the localized "No access" label by the time hiddenIf
        // evaluates client-side against the projected row, so grant is
        // offered exactly when that label is showing.
        return $s->action('userGrant')->label(__('tbtop-cms::users.actions.grant'))
            ->modal(__('tbtop-cms::users.actions.grant_title'), $form)
            ->hiddenIf(Cond::any(Cond::eq('is_self', true), Cond::neq('cms_role', __('tbtop-cms::users.no_access'))));
    }

    private function grant(ActionCtx $ctx): Effects
    {
        $userId = (int) ($ctx->row['id'] ?? 0);

        try {
            $this->assertNotSelf($userId);
            $this->assertNoExistingProfile($userId);
            $role = $this->assertAssignable((string) ($ctx->form['role'] ?? ''));
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        // role isn't mass-assignable (CmsUserProfile::$fillable is
        // user_id-only, same as the other admin-only columns) — forceFill
        // like ProfileActions does for its own admin-only writes.
        (new CmsUserProfile)->forceFill(['user_id' => $userId, 'role' => $role])->save();

        return Effects::make()->notify(__('tbtop-cms::users.actions.granted'))->closeModal()->refreshTable();
    }

    private function changeRoleAction(S $s): ActionBuilder
    {
        $acting = CurrentAdmin::role();

        $cancel = $s->action('userChangeRoleCancel')->label(__('tbtop-cms::users.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $save = $s->action('userChangeRoleSave')->label(__('tbtop-cms::users.actions.save'))->color('primary')
            ->handle($this->changeRole(...), needs: ['row', 'form']);

        $form = $s->form('userChangeRole', [
            $s->select('role')->label(__('tbtop-cms::users.fields.role'))->required()
                ->options(UsersPageOptions::assignableRoles($acting)),
            $s->flex([$s->spacer(), $cancel, $save], justify: 'end'),
        ]);

        // manageable is only true when a profile row exists AND its role is
        // one the acting role may manage — a missing row (nothing to change)
        // and an out-of-reach row (e.g. administrator viewing a superadmin)
        // both hide this action without a separate empty-row check.
        return $s->action('userChangeRole')->label(__('tbtop-cms::users.actions.change_role'))
            ->modal(__('tbtop-cms::users.actions.change_role_title'), $form)
            ->query($this->loadRole(...), needs: ['row'])
            ->hiddenIf(Cond::any(Cond::eq('is_self', true), Cond::eq('manageable', false)));
    }

    /** @return array<string, mixed> */
    private function loadRole(ActionCtx $ctx): array
    {
        $userId = (int) ($ctx->row['id'] ?? 0);
        $profile = CmsUserProfile::query()->where('user_id', $userId)->first();

        return ['role' => $profile?->role->value];
    }

    private function changeRole(ActionCtx $ctx): Effects
    {
        $userId = (int) ($ctx->row['id'] ?? 0);

        try {
            $this->assertNotSelf($userId);
            $profile = $this->assertManageableProfile($userId);
            $role = $this->assertAssignable((string) ($ctx->form['role'] ?? ''));
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        $profile->forceFill(['role' => $role])->save();

        return Effects::make()->notify(__('tbtop-cms::users.actions.role_changed'))->closeModal()->refreshTable();
    }

    private function revokeAction(S $s): ActionBuilder
    {
        return $s->action('userRevoke')->label(__('tbtop-cms::users.actions.revoke'))->color('danger')
            ->confirm(__('tbtop-cms::users.actions.revoke_title'), __('tbtop-cms::users.actions.revoke_confirm'))
            ->handle($this->revoke(...), needs: ['row'])
            ->hiddenIf(Cond::any(Cond::eq('is_self', true), Cond::eq('manageable', false)));
    }

    private function revoke(ActionCtx $ctx): Effects
    {
        $userId = (int) ($ctx->row['id'] ?? 0);

        try {
            $this->assertNotSelf($userId);
            $profile = $this->assertManageableProfile($userId);
        } catch (ValidationException $e) {
            return Effects::make()->notify(self::firstError($e), 'error');
        }

        $profile->delete();

        return Effects::make()->notify(__('tbtop-cms::users.actions.revoked'))->refreshTable();
    }

    private function assertNotSelf(int $userId): void
    {
        $currentId = CurrentAdmin::user()?->getKey();
        if ($currentId !== null && (int) $currentId === $userId) {
            throw ValidationException::withMessages(['role' => __('tbtop-cms::users.validation.cannot_manage_self')]);
        }
    }

    private function assertNoExistingProfile(int $userId): void
    {
        if (CmsUserProfile::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['role' => __('tbtop-cms::users.validation.already_has_access')]);
        }
    }

    private function assertManageableProfile(int $userId): CmsUserProfile
    {
        $profile = CmsUserProfile::query()->where('user_id', $userId)->first();
        if ($profile === null) {
            throw ValidationException::withMessages(['role' => __('tbtop-cms::users.validation.no_access')]);
        }

        if (! CurrentAdmin::role()->canManage($profile->role)) {
            throw ValidationException::withMessages(['role' => __('tbtop-cms::users.validation.cannot_manage_role')]);
        }

        return $profile;
    }

    private function assertAssignable(string $roleValue): CmsRole
    {
        $role = self::roleFromValue($roleValue);
        $acting = CurrentAdmin::role();

        if ($role === null || ! in_array($role, $acting->assignableRoles(), true)) {
            throw ValidationException::withMessages(['role' => __('tbtop-cms::users.validation.cannot_assign_role')]);
        }

        return $role;
    }

    private static function roleFromValue(string $value): ?CmsRole
    {
        return CmsRole::tryFrom($value);
    }

    /** @return class-string<Model> */
    private function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $model;
    }

    /**
     * Action endpoints have no field-error channel (a thrown
     * ValidationException turns into a redirect the client silently
     * ignores) — surface the message inside the still-open modal instead.
     * Mirrors RedirectsPage::haltWithErrors.
     */
    private static function haltWithErrors(ValidationException $e): Effects
    {
        return Effects::make()->haltModal(self::firstError($e));
    }

    private static function firstError(ValidationException $e): string
    {
        $first = collect($e->errors())->flatten()->first();

        return is_string($first) ? $first : $e->getMessage();
    }
}
