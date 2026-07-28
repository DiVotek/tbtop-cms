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
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * Grants/revokes cms admin access for EXISTING host-app users. Does not
 * create users or manage passwords — that's the host app's concern (see
 * cms:admin:grant/revoke for the CLI equivalent).
 *
 * Access is binary: a CmsUserProfile row means admin access, no row means
 * none. What an admin may then do is the host's business via tbtop-cms.roles
 * — this page never reads or writes a role.
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
        return CmsAbilities::for('tbtop-cms.users');
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
                // formatUsing runs before hiddenIf() reads the value, so the
                // grant/revoke actions compare against these labels, not the
                // raw 0/1 the driver returns.
                Column::make('has_access')->label(__('tbtop-cms::users.columns.access'))
                    ->formatUsing($this->accessLabel(...))
                    ->badge([
                        __('tbtop-cms::users.access.granted') => Color::Info,
                        __('tbtop-cms::users.access.none') => Color::Gray,
                    ]),
                // Not shown by default — projected purely so hiddenIf() can
                // gate row actions client-side (mirrors PagesPage's
                // deleted_at). A raw SQL expression, not a cast attribute, so
                // formatUsing forces a real PHP bool onto the wire.
                Column::make('is_self')->label('is_self')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
            ])
            ->searchable(['name', 'email'])
            ->defaultSort('name', 'asc')
            ->emptyState(__('tbtop-cms::users.empty'))
            ->rowActions([
                $this->grantAction($s),
                $this->revokeAction($s),
            ]);

        return $s->stack([$table]);
    }

    /**
     * One query, no N+1: has_access comes from a correlated subselect over
     * cms_user_profiles (same shape as PagesPage's parent_name subselect);
     * is_self is computed the same way so hiddenIf() on the row actions
     * never needs a second pass.
     *
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $model = $this->userModel();
        $table = (new $model)->getTable();
        $currentId = CurrentAdmin::user()?->getKey();

        return $model::query()
            ->select($table . '.*')
            ->selectSub(
                CmsUserProfile::query()->selectRaw('1')
                    ->whereColumn('user_id', $table . '.id')
                    ->limit(1),
                'has_access',
            )
            ->selectRaw(
                $currentId !== null ? $table . '.id = ? as is_self' : '0 as is_self',
                $currentId !== null ? [$currentId] : [],
            );
    }

    private function accessLabel(mixed $value): string
    {
        return (bool) $value
            ? __('tbtop-cms::users.access.granted')
            : __('tbtop-cms::users.access.none');
    }

    private function grantAction(S $s): ActionBuilder
    {
        return $s->action('userGrant')->label(__('tbtop-cms::users.actions.grant'))
            ->confirm(__('tbtop-cms::users.actions.grant_title'), __('tbtop-cms::users.actions.grant_confirm'))
            ->handle($this->grant(...), needs: ['row'])
            ->hiddenIf(Cond::any(
                Cond::eq('is_self', true),
                Cond::neq('has_access', __('tbtop-cms::users.access.none')),
            ));
    }

    private function grant(ActionCtx $ctx): Effects
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertNotSelf($userId);
            $this->assertNoExistingProfile($userId);
        } catch (ValidationException $e) {
            return Effects::make()->notify(self::firstError($e), 'error');
        }

        CmsUserProfile::query()->create(['user_id' => $userId]);

        return Effects::make()->notify(__('tbtop-cms::users.actions.granted'))->refreshTable();
    }

    private function revokeAction(S $s): ActionBuilder
    {
        return $s->action('userRevoke')->label(__('tbtop-cms::users.actions.revoke'))->color('danger')
            ->confirm(__('tbtop-cms::users.actions.revoke_title'), __('tbtop-cms::users.actions.revoke_confirm'))
            ->handle($this->revoke(...), needs: ['row'])
            ->hiddenIf(Cond::any(
                Cond::eq('is_self', true),
                Cond::eq('has_access', __('tbtop-cms::users.access.none')),
            ));
    }

    private function revoke(ActionCtx $ctx): Effects
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertNotSelf($userId);
            $profile = $this->assertExistingProfile($userId);
        } catch (ValidationException $e) {
            return Effects::make()->notify(self::firstError($e), 'error');
        }

        $profile->delete();

        return Effects::make()->notify(__('tbtop-cms::users.actions.revoked'))->refreshTable();
    }

    /**
     * Self-revoke is the only guard left on this page, so the comparison must
     * hold for every key type the package supports (int, uuid, ulid) — casting
     * to int would collapse every string key to 0 and match the wrong user.
     */
    private function assertNotSelf(int | string $userId): void
    {
        $currentId = CurrentAdmin::user()?->getKey();
        if ($currentId !== null && (string) $currentId === (string) $userId) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.cannot_manage_self')]);
        }
    }

    private function assertNoExistingProfile(int | string $userId): void
    {
        if (CmsUserProfile::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.already_has_access')]);
        }
    }

    private function assertExistingProfile(int | string $userId): CmsUserProfile
    {
        $profile = CmsUserProfile::query()->where('user_id', $userId)->first();
        if ($profile === null) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.no_access')]);
        }

        return $profile;
    }

    private static function rowId(ActionCtx $ctx): int | string
    {
        $id = $ctx->row['id'] ?? '';

        return is_int($id) ? $id : (string) $id;
    }

    /** @return class-string<Model> */
    private function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $model;
    }

    private static function firstError(ValidationException $e): string
    {
        $first = collect($e->errors())->flatten()->first();

        return is_string($first) ? $first : $e->getMessage();
    }
}
