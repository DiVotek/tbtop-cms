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
     * A page created in the admin starts with a copy of these blocks in its
     * content (unless it inherits a category's starter blocks); a block may
     * leave out id, active, sort and data. Called there on the unsaved page.
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

    /**
     * Reverse lists added to `page.fields`: key => the pages-kind field on
     * other pages' types that points here (`field`), optionally only from
     * pages whose type or subtype key is `from`. Each resolves to the live
     * referencing pages as `list<{id, name, url}>`, by sort_order then name.
     * A subtype's declarations merge over its base type's.
     *
     * @return array<string, array{field: string, from?: string}>
     */
    public function backReferences(): array;

    /**
     * The subtype a new article under a page of this type gets when none is
     * picked (a "Services" category's children are services). Read from the
     * parent's subtype first, then its base; only a subtype of the article
     * base applies. Null: no default.
     */
    public function childSubtype(): ?string;
}
