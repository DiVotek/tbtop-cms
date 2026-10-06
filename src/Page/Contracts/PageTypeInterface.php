<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Contracts;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\ResolveContext;
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
     * type. A page stores its base in `type` and a subtype's key in
     * `subtype`; tree rules (allowedChildren, requiresParent, maxDepth,
     * isSourceBacked) are always read from the base.
     */
    public function base(): string;

    /**
     * The type's own fields, in the same DSL as Block::form(). They show on
     * the page's General tab, are stored in the page's `fields` column and
     * reach the site as `page.fields`. A subtype lists only what it adds:
     * the page gets the base type's fields followed by the subtype's.
     *
     * @return list<Field>
     */
    public function fields(S $s): array;

    /**
     * Values for `page.fields` from the stored input, already flattened to
     * the current locale. Like Block::resolve(): override to compute values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveFields(array $input, ResolveContext $ctx): array;
}
