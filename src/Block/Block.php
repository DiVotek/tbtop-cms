<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

abstract class Block
{
    abstract public function kind(): string;

    /** @return list<Field> */
    abstract public function form(S $s): array;

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
