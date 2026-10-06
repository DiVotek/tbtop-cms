<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Support;

use Tbtop\Admin\Dsl\Fields\Field;

/** Dotted paths of the translatable fields in a form, nested repeaters included — what LocaleFlattener needs. */
final class TranslatablePaths
{
    /**
     * @param  list<Field>  $fields
     * @return list<string>
     */
    public static function of(array $fields, string $prefix = ''): array
    {
        $paths = [];

        foreach ($fields as $field) {
            $path = $prefix === '' ? $field->name : "{$prefix}.{$field->name}";

            if ($field->isTranslatableField()) {
                $paths[] = $path;
            }

            /** @var list<Field> $children */
            $children = $field->childFields();
            $paths = [...$paths, ...self::of($children, $path)];
        }

        return $paths;
    }
}
