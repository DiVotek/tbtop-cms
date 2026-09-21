<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use Illuminate\Support\Str;

/** Naming convention shared by every emitted `const`/`type` pair: kind "ContactForm" -> const `contactFormProps`, type `ContactFormProps`. */
final class EmitterNames
{
    public static function propsConst(string $name): string
    {
        return Str::camel($name) . 'Props';
    }

    public static function propsType(string $name): string
    {
        return Str::studly($name) . 'Props';
    }

    public static function aliasConst(string $aliasName): string
    {
        return self::propsConst($aliasName);
    }
}
