<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Str;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return $input;
    }

    /** @return list<string> */
    public function translatableFields(): array
    {
        return self::collectTranslatablePaths($this->form(new S), '');
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
     * @param  list<Field>  $fields
     * @return list<string>
     */
    private static function collectTranslatablePaths(array $fields, string $prefix): array
    {
        $paths = [];

        foreach ($fields as $field) {
            $path = $prefix === '' ? $field->name : "{$prefix}.{$field->name}";

            if ($field->isTranslatableField()) {
                $paths[] = $path;
            }

            /** @var list<Field> $children */
            $children = $field->childFields();
            $paths = [...$paths, ...self::collectTranslatablePaths($children, $path)];
        }

        return $paths;
    }
}
