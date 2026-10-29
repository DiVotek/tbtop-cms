<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Str;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Block\Support\TranslatablePaths;
use Tbtop\Cms\Frontend\Markdown\MarkdownContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;

abstract class Block
{
    abstract public function kind(): string;

    /** @return list<Field> */
    abstract public function form(S $s): array;

    /**
     * Human name shown on the block's editor tab, in the add-block picker
     * and in the blocks showcase. Defaults to a title-cased kind
     * ("ContactForm" -> "Contact Form"); override for a nicer label.
     */
    public function label(): string
    {
        return Str::headline($this->kind());
    }

    /**
     * One-line explanation shown in the add-block picker and the showcase.
     * Null hides the line —
     * override to document what the block is for.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Optional grouping bucket for the add-block picker and the showcase
     * (e.g. "content", "layout").
     * Null leaves the block ungrouped.
     */
    public function category(): ?string
    {
        return null;
    }

    /**
     * Page-type keys and/or Placement::LAYOUT. Default (Placement::PAGES)
     * means every page type; a layout-only block returns [Placement::LAYOUT].
     *
     * @return list<string>
     */
    public function placements(): array
    {
        return [Placement::PAGES];
    }

    /**
     * Props for the React component. Without an override the form decides:
     * every field is resolved by its kind (see FormResolver), and
     * cms:blocks:types derives the zod schema from the same rules. Override
     * for a shape the form cannot express, optionally starting from
     * parent::resolve() and reshaping; then declare the shape in a
     * `@return array{...}` docblock.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return (new FormResolver)->resolve($this->form(new S), $input, $ctx);
    }

    /** @return list<string> */
    public function translatableFields(): array
    {
        return TranslatablePaths::of($this->form(new S));
    }

    /**
     * This block's own schema.org node for the page's `seo.jsonLd`, or null
     * to publish nothing — most blocks have no structured-data opinion, and
     * null is simply dropped rather than an empty placeholder. `$props` are
     * this block's already-resolved output (the same shape `resolve()`
     * returns: absolute media URLs, rendered richtext HTML), not the raw
     * envelope data. Page-level values — `$ctx->url` for `mainEntityOfPage`,
     * `$ctx->publishedAt()` for a date — come from `$ctx`, never from
     * `request()`: this runs outside any HTTP context in some callers.
     *
     * A container block that nests other blocks collects its children's
     * nodes itself; the package does not walk into nested block content on
     * a block's behalf.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>|null
     */
    public function toSchema(array $props, SchemaContext $ctx): ?array
    {
        return null;
    }

    /**
     * This block in the page's markdown version (`/about.md`, a request
     * with `Accept: text/markdown`). Null keeps the fallback: the text of
     * the block's translatable fields, in form order, richtext converted.
     * An empty string leaves the block out (a decorative block, a form an
     * agent cannot fill in). `$props` are the resolved props, as in
     * toSchema(). Use `$ctx` helpers (`heading()`, `text()`, `link()`,
     * `list()`, `richtext()`) so stored text cannot break the markup; the
     * page heading is the only H1, so a block's own heading is H2.
     *
     * @param  array<string, mixed>  $props
     */
    public function toMarkdown(array $props, MarkdownContext $ctx): ?string
    {
        return null;
    }
}
