<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Str;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

abstract class Block
{
    abstract public function kind(): string;

    /** @return list<Field> */
    abstract public function form(S $s): array;

    /**
     * Human name for the blocks showcase. Defaults to a title-cased kind
     * ("ContactForm" -> "Contact Form"); override for a nicer label.
     */
    public function label(): string
    {
        return Str::headline($this->kind());
    }

    /**
     * One-line explanation shown in the showcase. Null hides the line —
     * override to document what the block is for.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Optional grouping bucket for the showcase (e.g. "content", "layout").
     * Null leaves the block ungrouped.
     */
    public function category(): ?string
    {
        return null;
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
