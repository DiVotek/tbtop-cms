<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Cms\Page\Models\Page;

/**
 * What one API token may reach, kept in the token's own Sanctum abilities so
 * it is issued, stored and revoked with the token. Three ways to name pages:
 * `pages:page:{id}` (that page alone, any type, a system page too),
 * `pages:parent:{id}` (a category, read, and its direct children), and
 * `pages:tree:{id}` (the page and every descendant, read). Children and
 * descendants are written only when their type is in `pages:type:{type}`.
 * System pages (home, error pages) are reached only by an explicit
 * `pages:page`, never through a parent or a tree. What the token may do is
 * `TokenAction`, and which fields it may write `FieldGroup` (none: all).
 * A token with no page, tree or parent (a host's `*` token) reaches nothing.
 * The site's 404 log and redirects are `SiteAbility`, named one by one: `*` and
 * `pages:write` grant none of them.
 */
final class TokenScope
{
    public const string READ = 'pages:read';

    public const string WRITE = TokenAction::LEGACY_WRITE;

    private const string PAGE = 'pages:page:';

    private const string PARENT = 'pages:parent:';

    private const string TREE = 'pages:tree:';

    private const string TYPE = 'pages:type:';

    /** @var list<int>|null */
    private ?array $treeMembers = null;

    /**
     * @param  list<int>  $parentIds
     * @param  list<string>  $childTypes
     * @param  list<int>  $pageIds
     * @param  list<int>  $treeIds
     * @param  list<TokenAction>|null  $actions  Null: every action, as a token issued before actions were split.
     * @param  list<FieldGroup>  $fieldGroups  Empty: every field.
     * @param  list<SiteAbility>  $site
     */
    public function __construct(
        public readonly array $parentIds,
        public readonly array $childTypes,
        public readonly array $pageIds = [],
        public readonly array $treeIds = [],
        ?array $actions = null,
        public readonly array $fieldGroups = [],
        public readonly array $site = [],
    ) {
        $this->actions = $actions ?? TokenAction::cases();
    }

    /** @var list<TokenAction> */
    public readonly array $actions;

    /** @param  list<mixed>  $abilities */
    public static function fromAbilities(array $abilities): self
    {
        $ids = [self::PAGE => [], self::PARENT => [], self::TREE => []];
        $types = [];
        $abilities = array_values(array_filter($abilities, is_string(...)));
        foreach ($abilities as $ability) {
            foreach (array_keys($ids) as $prefix) {
                if (str_starts_with($ability, $prefix) && ctype_digit(substr($ability, strlen($prefix)))) {
                    $ids[$prefix][] = (int) substr($ability, strlen($prefix));
                }
            }
            if (str_starts_with($ability, self::TYPE) && strlen($ability) > strlen(self::TYPE)) {
                $types[] = substr($ability, strlen(self::TYPE));
            }
        }

        // Sanctum lets `*` through every route middleware, so it is reported as every action.
        $actions = in_array('*', $abilities, true) ? TokenAction::cases() : array_values(array_filter(
            TokenAction::cases(),
            static fn (TokenAction $action): bool => array_intersect($action->accepted(), $abilities) !== [],
        ));
        $groups = array_values(array_filter(
            FieldGroup::cases(),
            static fn (FieldGroup $group): bool => in_array($group->ability(), $abilities, true),
        ));

        return new self(
            parentIds: array_values(array_unique($ids[self::PARENT])),
            childTypes: array_values(array_unique($types)),
            pageIds: array_values(array_unique($ids[self::PAGE])),
            treeIds: array_values(array_unique($ids[self::TREE])),
            actions: $actions,
            fieldGroups: $groups,
            site: array_values(array_filter(
                SiteAbility::cases(),
                static fn (SiteAbility $ability): bool => in_array($ability->value, $abilities, true),
            )),
        );
    }

    /**
     * The abilities to store. Every action granted is the legacy
     * `pages:read` + `pages:write` pair, so a token issued without the new
     * options matches those issued before them.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $isLegacyWriter = count(array_unique(array_column($this->actions, 'value'))) === count(TokenAction::cases());

        return [
            ...($isLegacyWriter ? [self::READ, self::WRITE] : array_map(static fn (TokenAction $action): string => $action->ability(), $this->actions)),
            ...array_map(static fn (int $id): string => self::PAGE . $id, $this->pageIds),
            ...array_map(static fn (int $id): string => self::TREE . $id, $this->treeIds),
            ...array_map(static fn (int $id): string => self::PARENT . $id, $this->parentIds),
            ...array_map(static fn (string $type): string => self::TYPE . $type, $this->childTypes),
            ...array_map(static fn (FieldGroup $group): string => $group->ability(), $this->fieldGroups),
            ...array_map(static fn (SiteAbility $ability): string => $ability->value, $this->site),
        ];
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function apply(Builder $query): Builder
    {
        return $query->where(fn (Builder $any): Builder => $any
            ->whereIn('id', $this->pageIds)
            ->orWhere(fn (Builder $named): Builder => $named
                ->where('is_system', false)
                ->where(fn (Builder $q): Builder => $q
                    ->whereIn('id', $this->parentIds)
                    ->orWhereIn('id', $this->treeMembers())
                    ->orWhere(fn (Builder $child): Builder => $child
                        ->whereIn('parent_id', $this->parentIds)
                        ->whereIn('type', $this->childTypes)))));
    }

    public function reads(Page $page): bool
    {
        if ($this->isNamed($page)) {
            return true;
        }

        return ! $page->is_system && (in_array((int) $page->getKey(), $this->parentIds, true) || in_array((int) $page->getKey(), $this->treeMembers(), true) || $this->writes($page));
    }

    public function writes(Page $page): bool
    {
        if ($this->isNamed($page)) {
            return true;
        }

        return ! $page->is_system && $page->parent_id !== null && $this->allowsChild($page->parent_id, $page->type);
    }

    /** A page may be created under a category the token names as a parent or holds inside a tree. */
    public function allowsChild(int $parentId, string $type): bool
    {
        return $this->reachesParent($parentId) && in_array($type, $this->childTypes, true);
    }

    public function reachesParent(int $parentId): bool
    {
        return in_array($parentId, $this->parentIds, true) || in_array($parentId, $this->treeMembers(), true);
    }

    /** Uploads are not tied to a page, so a token qualifies by naming any page, tree or parent at all. */
    public function reachesAnything(): bool
    {
        return $this->pageIds !== [] || $this->treeIds !== [] || $this->parentIds !== [];
    }

    public function allowsType(string $type): bool
    {
        return in_array($type, $this->childTypes, true);
    }

    /** @return list<string> */
    public function writableGroups(): array
    {
        return array_map(static fn (FieldGroup $group): string => $group->value, $this->fieldGroups);
    }

    public function can(TokenAction $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function canSite(SiteAbility $ability): bool
    {
        return in_array($ability, $this->site, true);
    }

    public function allowsGroup(FieldGroup $group): bool
    {
        return $this->fieldGroups === [] || in_array($group, $this->fieldGroups, true);
    }

    private function isNamed(Page $page): bool
    {
        return in_array((int) $page->getKey(), $this->pageIds, true);
    }

    /**
     * The tree roots (never a system page) and every page below them, any depth.
     *
     * @return list<int>
     */
    public function treeMembers(): array
    {
        if ($this->treeMembers !== null) {
            return $this->treeMembers;
        }

        $members = $this->treeIds === [] ? [] : array_map(intval(...), Page::query()->whereIn('id', $this->treeIds)->where('is_system', false)->pluck('id')->all());
        // The walk passes through a system page so its children stay in the tree, but the system page itself never joins.
        $seen = $members;
        $frontier = $members;
        while ($frontier !== []) {
            $children = Page::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $seen)->get(['id', 'is_system']);
            $frontier = array_map(static fn (Page $page): int => (int) $page->getKey(), $children->all());
            $seen = [...$seen, ...$frontier];
            $members = [...$members, ...array_map(static fn (Page $page): int => (int) $page->getKey(), $children->reject(static fn (Page $page): bool => $page->is_system)->all())];
        }

        return $this->treeMembers = array_values($members);
    }
}
