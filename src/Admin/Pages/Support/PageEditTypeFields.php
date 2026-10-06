<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * The General tab's type fields (PageTypeInterface::fields()): a section for
 * the base type, then one for the subtype, each field renamed `field_{name}`
 * — the same folding the block tabs do with `block_{id}_` — and read back
 * into the page's `fields` column by fold().
 *
 * Only the names the page's current types declare are shown, read or
 * written. Anything else in `fields` (left by an earlier subtype) is carried
 * through untouched, so switching the subtype back finds its values.
 */
final class PageEditTypeFields
{
    private const string PREFIX = 'field_';

    /** @return list<Node> */
    public static function build(S $s, Page $page): array
    {
        $sections = [];

        foreach (app(PageTypeRegistry::class)->forPage($page) as $type) {
            $fields = $type->fields($s);
            if ($fields === []) {
                continue;
            }

            $sections[] = $s->section(
                ['title' => PageFormOptions::typeLabelFor($type->key(), $type->label())],
                array_map(static fn (Field $field): Field => PrefixedField::rename($field, self::PREFIX . $field->name), $fields),
            );
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    public static function recordValues(Page $page): array
    {
        $stored = is_array($page->fields) ? $page->fields : [];
        $values = [];

        foreach (self::declaredNames($page) as $name) {
            if (array_key_exists($name, $stored)) {
                $values[self::PREFIX . $name] = $stored[$name];
            }
        }

        return $values;
    }

    /**
     * The stored `fields` with the submitted `field_*` values laid over them.
     * Read against the page as stored — the types the form was rendered for.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function fold(array $form, Page $page): array
    {
        $fields = is_array($page->fields) ? $page->fields : [];

        foreach (self::declaredNames($page) as $name) {
            if (array_key_exists(self::PREFIX . $name, $form)) {
                $fields[$name] = $form[self::PREFIX . $name];
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private static function declaredNames(Page $page): array
    {
        $names = [];
        foreach (app(PageTypeRegistry::class)->forPage($page) as $type) {
            foreach ($type->fields(new S) as $field) {
                $names[] = $field->name;
            }
        }

        return array_values(array_unique($names));
    }
}
