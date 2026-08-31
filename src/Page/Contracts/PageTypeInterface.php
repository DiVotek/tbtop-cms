<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Contracts;

use Tbtop\Cms\Page\Models\Page;

interface PageTypeInterface
{
    public function key(): string;

    public function label(): string;

    /** @return list<string> */
    public function allowedChildren(): array;

    public function requiresParent(): bool;

    /** Max nesting depth for container types; leaf types are not depth-checked. */
    public function maxDepth(): int;

    /** The source_type value this type owns ('product', 'variant', ...); null for content-only types. */
    public function sourceType(): ?string;

    /**
     * The domain object this page shows, loaded by (source_type, source_id);
     * null for content-only types and when the source no longer exists.
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
}
