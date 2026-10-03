<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Contracts;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\TypeBlock;

interface PageTypeInterface
{
    public function key(): string;

    public function label(): string;

    /** @return list<string> */
    public function allowedChildren(): array;

    public function requiresParent(): bool;

    /** Max nesting depth for container types; leaf types are not depth-checked. */
    public function maxDepth(): int;

    /**
     * True for a type whose pages are created by an external source and
     * rendered from source()/template(); such pages cannot be created by
     * hand in cms.
     */
    public function isSourceBacked(): bool;

    /**
     * The domain object this page shows, loaded by (type, source_id); null
     * for content-only types and when the source no longer exists.
     * Available to every block as ResolveContext::$source.
     */
    public function source(Page $page): ?object;

    /**
     * Block content to render when $page->content is null. Content-only types
     * return null (an empty page). A source-backed type returns its kind's
     * template. Same envelope shape as Page::$content.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function template(Page $page): ?array;

    /**
     * Schema.org node keys this page type may publish, resolved through
     * SchemaTypeRegistry. The first element is the default when no valid
     * choice is stored.
     *
     * @return non-empty-list<string>
     */
    public function schemaTypes(): array;

    /**
     * The type this one is a subtype of, or its own key for a top-level
     * type. The editor offers top-level types in "Type" and the subtypes of
     * `page` in "Subtype"; the stored `type` is always this type's key.
     */
    public function base(): string;

    /**
     * Blocks every page of this type carries: created with the page, never
     * removable in the editor, their fields are the type's own fields. A
     * general block has no place in the content flow — its form shows on
     * the General tab and its resolved props become the page's `fields`.
     *
     * @return list<TypeBlock>
     */
    public function blocks(): array;
}
