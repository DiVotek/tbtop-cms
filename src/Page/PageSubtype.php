<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page;

/**
 * The editor's two-step type pick: "Type" holds a top-level type, "Subtype"
 * a type whose base() is `page`. The page stores one key — the subtype when
 * one is picked, the base otherwise — so hierarchy rules, type blocks and
 * everything keyed on `type` are untouched by the split.
 */
final class PageSubtype
{
    public const string BASE = 'page';

    /**
     * Folds `subtype` into `type` for PageForm::save(). A `type` that is
     * not the base (a direct key, as the API or older callers send it) is
     * kept as is.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function resolve(array $form): array
    {
        $subtype = $form['subtype'] ?? null;
        unset($form['subtype']);

        if (($form['type'] ?? null) === self::BASE && is_string($subtype) && $subtype !== '') {
            $form['type'] = $subtype;
        }

        return $form;
    }

    /** The "Type" select value for a stored key: its base when it is a page subtype. */
    public static function baseOf(string $type): string
    {
        $registry = app(PageTypeRegistry::class);

        return $registry->has($type) ? $registry->get($type)->base() : $type;
    }

    /** The "Subtype" select value for a stored key: the key itself when it is a page subtype, else ''. */
    public static function subtypeOf(string $type): string
    {
        return self::baseOf($type) === self::BASE && $type !== self::BASE ? $type : '';
    }
}
