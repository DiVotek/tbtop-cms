<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Cms\Page\Models\Page;

/**
 * What one API token may reach, kept in the token's own Sanctum abilities so
 * it is issued, stored and revoked with the token: `pages:parent:{id}` per
 * allowed parent and `pages:type:{type}` per child type. A token reads its
 * parents and their direct children of those types, and writes only the
 * children. A token with none of them (a host's `*` token) reaches nothing.
 */
final readonly class TokenScope
{
    public const string READ = 'pages:read';

    public const string WRITE = 'pages:write';

    private const string PARENT = 'pages:parent:';

    private const string TYPE = 'pages:type:';

    /**
     * @param  list<int>  $parentIds
     * @param  list<string>  $childTypes
     */
    public function __construct(public array $parentIds, public array $childTypes) {}

    /** @param  list<mixed>  $abilities */
    public static function fromAbilities(array $abilities): self
    {
        $parentIds = [];
        $childTypes = [];
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                continue;
            }
            if (str_starts_with($ability, self::PARENT) && ctype_digit(substr($ability, strlen(self::PARENT)))) {
                $parentIds[] = (int) substr($ability, strlen(self::PARENT));
            } elseif (str_starts_with($ability, self::TYPE) && strlen($ability) > strlen(self::TYPE)) {
                $childTypes[] = substr($ability, strlen(self::TYPE));
            }
        }

        return new self(array_values(array_unique($parentIds)), array_values(array_unique($childTypes)));
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return [
            self::READ,
            self::WRITE,
            ...array_map(static fn (int $id): string => self::PARENT . $id, $this->parentIds),
            ...array_map(static fn (string $type): string => self::TYPE . $type, $this->childTypes),
        ];
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function apply(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereIn('id', $this->parentIds === [] ? [0] : $this->parentIds)
            ->orWhere(fn (Builder $child): Builder => $child
                ->whereIn('parent_id', $this->parentIds === [] ? [0] : $this->parentIds)
                ->whereIn('type', $this->childTypes === [] ? [''] : $this->childTypes)));
    }

    public function reads(Page $page): bool
    {
        return in_array((int) $page->getKey(), $this->parentIds, true) || $this->writes($page);
    }

    public function writes(Page $page): bool
    {
        return $page->parent_id !== null && $this->allowsChild($page->parent_id, $page->type);
    }

    public function allowsChild(int $parentId, string $type): bool
    {
        return in_array($parentId, $this->parentIds, true) && in_array($type, $this->childTypes, true);
    }

    public function allowsType(string $type): bool
    {
        return in_array($type, $this->childTypes, true);
    }
}
