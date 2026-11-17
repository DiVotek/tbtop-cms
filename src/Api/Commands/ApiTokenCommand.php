<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Tbtop\Cms\Admin\Commands\Concerns\InteractsWithAdminUsers;
use Tbtop\Cms\Admin\Pages\Support\PageLeafType;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Api\ApiWiring;
use Tbtop\Cms\Api\FieldGroup;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Api\PageApiWriter;
use Tbtop\Cms\Api\TokenAction;
use Tbtop\Cms\Api\TokenScope;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Issues a pages-API token for a CMS admin, limited to the given pages,
 * trees or categories, the actions and the field groups, prints that scope
 * and then the token once: Sanctum keeps only its hash. Without the new
 * options it issues the abilities it always did.
 */
class ApiTokenCommand extends Command
{
    use InteractsWithAdminUsers;

    protected $signature = 'cms:api-token {user : The email of a CMS admin}
        {--parents= : Comma-separated ids of categories: read the category, read and write its direct children}
        {--pages= : Comma-separated ids of pages the token reaches alone, a system page (home) included}
        {--trees= : Comma-separated ids of pages the token reaches with every descendant, system pages excluded}
        {--types=article : Comma-separated child types written under the parents and trees}
        {--actions= : Comma-separated read, create, update, publish, delete, media (default: all)}
        {--fields= : Comma-separated field groups seo, heading, text, url, type, media that may be written (default: all)}
        {--name=cms-api : The token\'s name}';

    protected $description = 'Issue a pages-API token limited to the given pages, actions and field groups.';

    public function handle(): int
    {
        if (! ApiWiring::isSanctumInstalled()) {
            $this->error(__('tbtop-cms::api.command.no_sanctum'));

            return self::FAILURE;
        }

        $email = (string) $this->argument('user');
        $user = $this->findUser($email);
        if (! $user instanceof Authenticatable) {
            $this->error(__('tbtop-cms::api.command.no_user', ['user' => $email]));

            return self::FAILURE;
        }
        if (! CurrentAdmin::allows($user)) {
            $this->error(__('tbtop-cms::api.command.not_admin', ['user' => $email]));

            return self::FAILURE;
        }
        if (! method_exists($user, 'createToken')) {
            $this->error(__('tbtop-cms::api.command.no_tokens', ['model' => $user::class]));

            return self::FAILURE;
        }

        $scope = $this->scope();
        if (! $scope instanceof TokenScope) {
            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'), $scope->abilities());

        if (! ApiWiring::isEnabled()) {
            $this->warn(__('tbtop-cms::api.command.disabled'));
        }
        $this->warnAboutSystemPages($scope);
        $this->info(__('tbtop-cms::api.command.issued', ['user' => $email, 'scope' => $this->describe($scope)]));
        $this->line(__('tbtop-cms::api.command.shown_once'));
        $this->line((string) $token->plainTextToken);

        return self::SUCCESS;
    }

    /** The scope the options describe, or null after reporting what is wrong with them. */
    private function scope(): ?TokenScope
    {
        $parentIds = $this->parentIds();
        $pageIds = $this->existingIds('pages', isSystemAllowed: true);
        $treeIds = $this->existingIds('trees', isSystemAllowed: false);
        $types = $this->choices('types', $this->baseTypes());
        $actions = $this->choices('actions', TokenAction::values(), TokenAction::values());
        $groups = $this->choices('fields', FieldGroup::values(), []);
        if (in_array(null, [$parentIds, $pageIds, $treeIds, $types, $actions, $groups], true)) {
            return null;
        }

        if ($parentIds === [] && $pageIds === [] && $treeIds === []) {
            $this->error(__('tbtop-cms::api.command.no_scope'));

            return null;
        }

        $scope = new TokenScope(
            parentIds: $parentIds,
            childTypes: $types,
            pageIds: $pageIds,
            treeIds: $treeIds,
            actions: array_map(TokenAction::from(...), $actions),
            fieldGroups: array_map(FieldGroup::from(...), $groups),
        );

        return $this->isCreateConsistent($scope) ? $scope : null;
    }

    /** Creating names the page (`name`, text) and, for a type with required fields, fills them (type). */
    private function isCreateConsistent(TokenScope $scope): bool
    {
        if (! $scope->can(TokenAction::Create) || $scope->fieldGroups === []) {
            return true;
        }
        if (! $scope->allowsGroup(FieldGroup::Text)) {
            $this->error(__('tbtop-cms::api.command.create_needs_text'));

            return false;
        }
        if (! $scope->allowsGroup(FieldGroup::Type) && $this->hasRequiredTypeFields($this->createParents($scope))) {
            $this->error(__('tbtop-cms::api.command.create_needs_type'));

            return false;
        }

        return true;
    }

    /**
     * Every category a create may name: the parents and each category inside a tree, since the
     * child type follows from the category it is created under, not from the tree root.
     *
     * @return list<int>
     */
    private function createParents(TokenScope $scope): array
    {
        $inTrees = $scope->treeMembers() === [] ? [] : Page::query()->whereIn('id', $scope->treeMembers())->where('type', 'category')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values(array_unique([...$scope->parentIds, ...$inTrees]));
    }

    /** @param  list<int>  $parentIds */
    private function hasRequiredTypeFields(array $parentIds): bool
    {
        foreach ($parentIds as $parentId) {
            ['type' => $type, 'subtype' => $subtype] = PageApiWriter::resolveType($parentId);
            foreach (PageFields::for($type, $subtype)->typeFields->all() as $field) {
                if ($field->isRequired) {
                    return true;
                }
            }
        }

        return false;
    }

    private function warnAboutSystemPages(TokenScope $scope): void
    {
        $isDestructive = $scope->can(TokenAction::Publish) || $scope->can(TokenAction::Delete);
        $system = $isDestructive ? Page::query()->whereIn('id', $scope->pageIds)->where('is_system', true)->pluck('id')->all() : [];
        if ($system !== []) {
            $this->warn(__('tbtop-cms::api.command.system_destructive', ['ids' => implode(', ', $system)]));
        }
    }

    private function describe(TokenScope $scope): string
    {
        $isDefaultType = $scope->childTypes === [PageLeafType::ARTICLE];
        $parts = [
            'scope_pages' => $scope->pageIds,
            'scope_trees' => $scope->treeIds,
            'scope_parents' => $scope->parentIds,
            'scope_types' => $isDefaultType ? [] : $scope->childTypes,
            'scope_actions' => array_map(static fn (TokenAction $action): string => $action->value, $scope->actions),
            'scope_fields' => $scope->fieldGroups === [] ? [__('tbtop-cms::api.command.all_fields')] : $scope->writableGroups(),
        ];

        $lines = [];
        foreach ($parts as $key => $values) {
            if ($values !== []) {
                $lines[] = __('tbtop-cms::api.command.' . $key, ['value' => implode(', ', array_map(strval(...), $values))]);
            }
        }

        return implode('; ', $lines);
    }

    /** @return list<string> The registered base types, the ones a token may name. */
    private function baseTypes(): array
    {
        $registry = app(PageTypeRegistry::class);

        return array_values(array_filter(
            $registry->keys(),
            static fn (string $key): bool => $registry->get($key)->base() === $key,
        ));
    }

    /**
     * A comma-separated option checked against the allowed values.
     *
     * @param  list<string>  $allowed
     * @param  list<string>|null  $default  Used when the option is absent; null takes the option's own default.
     * @return list<string>|null Null after naming the values that are not allowed.
     */
    private function choices(string $option, array $allowed, ?array $default = null): ?array
    {
        $given = $this->csv($option);
        if ($given === []) {
            return $default ?? [PageLeafType::ARTICLE];
        }

        $unknown = array_values(array_diff($given, $allowed));
        if ($unknown !== []) {
            $this->error(__('tbtop-cms::api.command.unknown_values', ['option' => $option, 'values' => implode(', ', $unknown), 'allowed' => implode(', ', $allowed)]));

            return null;
        }

        return $given;
    }

    /**
     * @return list<int>|null Null after naming the ids that are no such page.
     */
    private function existingIds(string $option, bool $isSystemAllowed): ?array
    {
        $given = $this->csv($option);
        $found = Page::query()
            ->whereIn('id', array_map(intval(...), array_filter($given, ctype_digit(...))))
            ->when(! $isSystemAllowed, static fn ($query) => $query->where('is_system', false))
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $unknown = array_values(array_filter($given, static fn (string $id): bool => ! ctype_digit($id) || ! in_array((int) $id, $found, true)));
        if ($unknown !== []) {
            $this->error(__('tbtop-cms::api.command.unknown_ids', ['option' => $option, 'ids' => implode(', ', $unknown)]));

            return null;
        }

        return array_map(intval(...), $given);
    }

    /** @return list<string> */
    private function csv(string $option): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option($option))), static fn (string $value): bool => $value !== '')));
    }

    /** @return list<int>|null Null after naming the ids that are not categories. */
    private function parentIds(): ?array
    {
        $given = $this->csv('parents');
        if ($given === []) {
            return [];
        }

        $found = Page::query()->where('type', 'category')
            ->whereIn('id', array_map(intval(...), array_filter($given, ctype_digit(...))))
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $unknown = array_values(array_filter($given, static fn (string $id): bool => ! ctype_digit($id) || ! in_array((int) $id, $found, true)));
        if ($unknown !== []) {
            $this->error(__('tbtop-cms::api.command.unknown_parents', ['ids' => implode(', ', $unknown)]));

            return null;
        }

        return array_map(intval(...), $given);
    }
}
