<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use Illuminate\Support\Str;

/** Naming convention shared by every emitted `const`/`type` pair: kind "ContactForm" -> const `contactFormProps`, type `ContactFormProps`. */
final class EmitterNames
{
    public static function propsConst(string $name): string
    {
        return self::identifier(Str::camel($name)) . 'Props';
    }

    public static function propsType(string $name): string
    {
        return self::identifier(Str::studly($name)) . 'Props';
    }

    /** kind() is any non-empty string; names that collapse together are caught by assertDistinctExportNames(). */
    private static function identifier(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_$]/', '', $name);

        return preg_match('/^[0-9]/', $name) === 1 ? "_{$name}" : $name;
    }

    public static function aliasConst(string $aliasName): string
    {
        return self::propsConst($aliasName);
    }

    /** A key that is not a JS identifier (or a plain index) must be quoted in an object literal. */
    public static function objectKey(string $key): string
    {
        return preg_match('/^(?:[A-Za-z_$][A-Za-z0-9_$]*|0|[1-9][0-9]*)$/', $key) === 1 ? $key : json_encode($key, JSON_THROW_ON_ERROR);
    }
}
