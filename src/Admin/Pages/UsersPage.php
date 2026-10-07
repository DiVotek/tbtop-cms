<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\UserForm;
use Tbtop\Cms\Admin\Support\AdminAccess;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * Creates, edits, and grants/revokes cms admin access for host-app users.
 * Create/edit are a UI mirror of cms:admin:create's assumptions (forceFill +
 * Hash::make — see UserForm); grant/revoke keep going through AdminAccess,
 * the single write path for CmsUserProfile rows.
 *
 * Access is binary: a CmsUserProfile row means admin access, no row means
 * none. What an admin may then do is the host's business via tbtop-cms.roles
 * — this page never reads or writes a role.
 *
 * Other admins' accounts belong to the admin manager only: the owner, plus —
 * when tbtop-cms.roles is on — whoever the host gives the
 * CmsAbilities::ADMINS gate. Any admin may edit their own row and rows
 * without CMS access (and grant those access); editing or revoking another
 * admin needs the admin manager. The owner row itself cannot be revoked, or
 * edited by anyone but the owner. Every rule is checked on the server: the
 * row id comes from the client, hidden buttons are only a convenience.
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
            'icon' => 'users',
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

    /** @return list<ActionBuilder|Node> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createUserAction($s)];
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
                // raw 'none'/'granted'/'owner' the query() tri-state emits.
                // The owner gets its own label/color here (not a separate
                // column) because formatUsing sees only this column's own
                // value — see query()'s has_access CASE.
                Column::make('has_access')->label(__('tbtop-cms::users.columns.access'))
                    ->formatUsing($this->accessLabel(...))
                    ->badge([
                        __('tbtop-cms::users.access.granted') => ['color' => Color::Info],
                        __('tbtop-cms::users.access.owner') => ['color' => Color::Success],
                        __('tbtop-cms::users.access.none') => ['color' => Color::Gray],
                        __('tbtop-cms::users.access.managed') => ['color' => Color::Gray],
                    ]),
                // Not shown by default — projected purely so hiddenIf() can
                // gate row actions client-side (mirrors PagesPage's
                // deleted_at). A raw SQL expression, not a cast attribute, so
                // formatUsing forces a real PHP bool onto the wire.
                Column::make('is_self')->label('is_self')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
                Column::make('is_owner')->label('is_owner')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
                // Whether the actor is the admin manager (same on every row).
                Column::make('manages_admins')->label('manages_admins')
                    ->toggleable(true, hiddenByDefault: true)
                    ->formatUsing(static fn (mixed $v): bool => (bool) $v),
            ])
            ->searchable(['name', 'email'])
            ->defaultSort('name', 'asc')
            ->emptyState(
                __('tbtop-cms::users.empty.title'),
                __('tbtop-cms::users.empty.description'),
            )
            ->rowActions([
                $this->grantAction($s),
                $this->revokeAction($s),
                $this->editAction($s),
            ]);

        return $s->stack([$table]);
    }

    /**
     * One query, no N+1: has_access/is_owner come from correlated subselects
     * over cms_user_profiles (same shape as PagesPage's parent_name
     * subselect); is_self is computed the same way so hiddenIf() on the row
     * actions never needs a second pass.
     *
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $model = $this->userModel();
        $table = (new $model)->getTable();
        $currentId = CurrentAdmin::user()?->getKey();
        // Built before the is_a() branch: larastan's Builder<T> is invariant, so a
        // query started from the narrowed class-string would not be Builder<Model>.
        $query = $model::query()->select($table . '.*');

        if (is_a($model, CmsAdmin::class, true)) {
            // The application decides access, so the list cannot tell an
            // admin from a stranger — but the owner still has a profile row
            // (cms:admin:create), and its Edit must stay hidden from others.
            $query->selectRaw("'managed' as has_access");
        } else {
            $query->selectSub(
                CmsUserProfile::query()->selectRaw("CASE WHEN is_owner THEN 'owner' ELSE 'granted' END")
                    ->whereColumn('user_id', $table . '.id')
                    ->limit(1),
                'has_access',
            );
        }

        return $query
            ->selectSub(
                CmsUserProfile::query()->selectRaw('1')
                    ->whereColumn('user_id', $table . '.id')
                    ->where('is_owner', true)
                    ->limit(1),
                'is_owner',
            )
            ->selectRaw(
                $currentId !== null ? $table . '.id = ? as is_self' : '0 as is_self',
                $currentId !== null ? [$currentId] : [],
            )
            ->selectRaw('? as manages_admins', [$this->actorManagesAdmins() ? 1 : 0]);
    }

    private function accessLabel(mixed $value): string
    {
        return match ($value) {
            'owner' => __('tbtop-cms::users.access.owner'),
            'granted' => __('tbtop-cms::users.access.granted'),
            'managed' => __('tbtop-cms::users.access.managed'),
            default => __('tbtop-cms::users.access.none'),
        };
    }

    private function grantAction(S $s): ActionBuilder
    {
        return $s->action('userGrant')->label(__('tbtop-cms::users.actions.grant'))
            ->confirm(__('tbtop-cms::users.actions.grant_title'), __('tbtop-cms::users.actions.grant_confirm'))
            ->handle($this->grant(...), needs: ['row'])
            ->hiddenIf(Cond::any(
                Cond::eq('is_self', true),
                Cond::eq('has_access', __('tbtop-cms::users.access.managed')),
                Cond::neq('has_access', __('tbtop-cms::users.access.none')),
            ));
    }

    private function grant(ActionCtx $ctx): Effects
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertProfileControlsAccess();
            $this->assertNotSelf($userId);
            $this->assertNoExistingProfile($userId);
        } catch (ValidationException $e) {
            return Effects::make()->notify(self::firstError($e), 'error');
        }

        AdminAccess::grant($userId);

        return Effects::make()->notify(__('tbtop-cms::users.actions.granted'))->refreshTable();
    }

    private function revokeAction(S $s): ActionBuilder
    {
        return $s->action('userRevoke')->label(__('tbtop-cms::users.actions.revoke'))->color('danger')
            ->confirm(__('tbtop-cms::users.actions.revoke_title'), __('tbtop-cms::users.actions.revoke_confirm'))
            ->handle($this->revoke(...), needs: ['row'])
            ->hiddenIf(Cond::any(
                Cond::eq('is_self', true),
                Cond::eq('has_access', __('tbtop-cms::users.access.managed')),
                Cond::eq('has_access', __('tbtop-cms::users.access.none')),
                Cond::eq('is_owner', true),
                Cond::neq('manages_admins', true),
            ));
    }

    private function revoke(ActionCtx $ctx): Effects
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertProfileControlsAccess();
            $this->assertNotSelf($userId);
            $this->assertExistingProfile($userId);
            $this->assertNotOwner($userId, 'cannot_revoke_owner');
            $this->assertManagesAdmins('revoke_not_allowed');
        } catch (ValidationException $e) {
            return Effects::make()->notify(self::firstError($e), 'error');
        }

        AdminAccess::revoke($userId);

        return Effects::make()->notify(__('tbtop-cms::users.actions.revoked'))->refreshTable();
    }

    private function editAction(S $s): ActionBuilder
    {
        $cancel = $s->action('userEditCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $save = $s->action('userEditSave')->label(__('tbtop-cms::users.actions.save'))->color('primary')
            ->handle($this->update(...), needs: ['row', 'form']);

        $form = $s->form('userEdit', [
            ...UserForm::editFields($s),
            $s->flex([$s->spacer(), $cancel, $save], justify: 'end'),
        ]);

        // ProfilePage stays the place for display-name/locale/avatar. In
        // CmsAdmin mode every row is 'managed', so Edit shows on other
        // admins too and assertMayEdit() answers not_allowed.
        return $s->action('userEdit')->label(__('tbtop-cms::users.actions.edit'))
            ->modal(__('tbtop-cms::users.actions.edit_title'), $form)
            ->query($this->loadEditRow(...), needs: ['row'])
            ->hiddenIf(Cond::any(
                Cond::all(
                    Cond::eq('is_owner', true),
                    Cond::neq('is_self', true),
                ),
                Cond::all(
                    Cond::eq('has_access', __('tbtop-cms::users.access.granted')),
                    Cond::neq('is_self', true),
                    Cond::neq('manages_admins', true),
                ),
            ));
    }

    /** @return array<string, mixed> */
    private function loadEditRow(ActionCtx $ctx): array
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertMayEdit($userId);
        } catch (ValidationException) {
            abort(403, __('tbtop-cms::users.errors.not_allowed'));
        }

        return UserForm::loadRow($this->userModel(), $userId);
    }

    private function update(ActionCtx $ctx): Effects
    {
        $userId = self::rowId($ctx);

        try {
            $this->assertMayEdit($userId);
            UserForm::update($ctx->form, $this->userModel(), $userId);
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        return Effects::make()->notify(__('tbtop-cms::users.actions.saved'))->closeModal()->refreshTable();
    }

    private function createUserAction(S $s): ActionBuilder
    {
        $cancel = $s->action('userCreateCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('userCreateSave')->label(__('tbtop-cms::users.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('userCreate', [
            ...UserForm::createFields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('userCreate')->label(__('tbtop-cms::users.actions.new'))
            ->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::users.actions.create_title'), $form);
    }

    private function create(ActionCtx $ctx): Effects
    {
        try {
            $model = $this->userModel();
            (new $model)->getConnection()->transaction(function () use ($ctx, $model): void {
                $user = UserForm::create($ctx->form, $model);
                $id = $user->getKey();
                AdminAccess::grant(is_int($id) ? $id : (string) $id);
            });
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        return Effects::make()->notify(__('tbtop-cms::users.actions.created'))->closeModal()->refreshTable();
    }

    private function assertNotSelf(int | string $userId): void
    {
        if ($this->isSelf($userId)) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.cannot_manage_self')]);
        }
    }

    private function assertMayEdit(int | string $userId): void
    {
        if ($this->isSelf($userId)) {
            return;
        }

        $this->assertNotOwner($userId, 'cannot_edit_owner');

        if ($this->targetIsAdmin($userId)) {
            $this->assertManagesAdmins('not_allowed');
        }
    }

    private function assertManagesAdmins(string $message): void
    {
        if (! $this->actorManagesAdmins()) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.errors.' . $message)]);
        }
    }

    /**
     * The admin manager: the owner in every mode, plus — only when
     * tbtop-cms.roles is on — holders of the CmsAbilities::ADMINS gate. With
     * roles off the gate is never read, so a host's leftover definition
     * cannot hand out the right.
     */
    private function actorManagesAdmins(): bool
    {
        $actor = CurrentAdmin::user();
        if (! $actor instanceof Model) {
            return false;
        }

        $actorId = $actor->getKey();
        if (AdminAccess::isOwner(is_int($actorId) ? $actorId : (string) $actorId)) {
            return true;
        }

        return CmsAbilities::enabled() && Gate::forUser($actor)->allows(CmsAbilities::ADMINS);
    }

    /**
     * Same rule that lets a user into the CMS (CurrentAdmin::allows): the
     * application's canAccessCmsAdmin() in CmsAdmin mode, a profile row
     * otherwise.
     */
    private function targetIsAdmin(int | string $userId): bool
    {
        $target = $this->userModel()::query()->find($userId);

        return $target instanceof Authenticatable && CurrentAdmin::allows($target);
    }

    /**
     * Must hold for every key type the package supports (int, uuid, ulid) —
     * casting to int would collapse every string key to 0 and match the
     * wrong user.
     */
    private function isSelf(int | string $userId): bool
    {
        $currentId = CurrentAdmin::user()?->getKey();

        return $currentId !== null && (string) $currentId === (string) $userId;
    }

    private function assertProfileControlsAccess(): void
    {
        if (is_a($this->userModel(), CmsAdmin::class, true)) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.access_managed_by_application')]);
        }
    }

    private function assertNoExistingProfile(int | string $userId): void
    {
        if (CmsUserProfile::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.already_has_access')]);
        }
    }

    private function assertExistingProfile(int | string $userId): void
    {
        if (! CmsUserProfile::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.no_access')]);
        }
    }

    /**
     * Owner protection: the target may not be revoked/edited unless the
     * actor IS that owner (self-revoke is already blocked separately by
     * assertNotSelf; self-edit of one's own owner row is allowed here).
     */
    private function assertNotOwner(int | string $userId, string $message): void
    {
        if (! AdminAccess::isOwner($userId) || $this->isSelf($userId)) {
            return;
        }

        throw ValidationException::withMessages(['user' => __('tbtop-cms::users.validation.' . $message)]);
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

    /**
     * Action endpoints have no field-error channel (a thrown
     * ValidationException turns into a redirect the client silently
     * ignores) — surface the message inside the still-open modal instead
     * (mirrors RedirectsPage::haltWithErrors()).
     */
    private static function haltWithErrors(ValidationException $e): Effects
    {
        return Effects::make()->haltModal(self::firstError($e));
    }
}
