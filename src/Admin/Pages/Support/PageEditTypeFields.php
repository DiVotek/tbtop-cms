<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * The General tab's type fields (PageTypeInterface::fields()): a section for
 * the base type, then one per subtype of it, each shown only while its
 * subtype is picked — so picking another subtype shows its fields before a
 * save. Base fields are renamed `field_{name}`, a subtype's
 * `field_{subtype}__{name}` (two subtypes may declare the same name) — the
 * same folding the block tabs do with `block_{id}_` — and fold() reads them
 * back into the page's `fields` column under the bare name. A name the
 * picked subtype shares with the base is the subtype's: one input, one
 * value, its declaration.
 *
 * Anything else in `fields` (left by an earlier subtype) is carried through
 * untouched, so switching the subtype back finds its values.
 */
final class PageEditTypeFields
{
    private const string PREFIX = 'field_';

    /** @return list<Node> */
    public static function build(S $s, Page $page): array
    {
        $sections = self::sections($page);
        $redeclaredBy = self::redeclaredBy($sections);
        $nodes = [];

        foreach ($sections as [$type, $subtype]) {
            $fields = $type->fields($s);
            if ($fields === []) {
                continue;
            }

            $nodes[] = $s->section(
                [
                    'title' => PageFormOptions::typeLabelFor($type->key(), $type->label()),
                    ...($subtype === null ? [] : ['hiddenIf' => Cond::neq('subtype', $subtype)]),
                ],
                array_map(
                    static fn (Field $field): Field => self::exceptSelf(
                        $subtype === null ? self::baseField($field, $redeclaredBy[$field->name] ?? []) : self::subtypeField($field, $subtype),
                        $page,
                    ),
                    $fields,
                ),
            );
        }

        return $nodes;
    }

    /** A pages field on a page does not offer that page itself. */
    public static function exceptSelf(Field $field, Page $page): Field
    {
        return $field instanceof Pages && $page->exists ? $field->exceptPage((int) $page->getKey()) : $field;
    }

    /** @return array<string, mixed> */
    public static function recordValues(Page $page): array
    {
        $stored = is_array($page->fields) ? $page->fields : [];
        $values = [];

        foreach (self::sections($page) as [$type, $subtype]) {
            foreach (self::names($type) as $name) {
                if (array_key_exists($name, $stored)) {
                    $values[self::inputName($subtype, $name)] = $stored[$name];
                }
            }
        }

        return $values;
    }

    /**
     * The stored `fields` with the submitted values of the base section and
     * the picked subtype's section laid over them. Picked = the subtype the
     * form submits, so switching the subtype and filling its fields is one
     * save; the sections are those of the stored type the form was rendered for.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function fold(array $form, Page $page): array
    {
        $fields = is_array($page->fields) ? $page->fields : [];
        $picked = array_key_exists('subtype', $form) ? $form['subtype'] : $page->subtype;

        // Name => the input that writes it; the subtype's input replaces the base's for a name both declare.
        $inputs = [];
        foreach (self::sections($page) as [$type, $subtype]) {
            if ($subtype !== null && $subtype !== $picked) {
                continue;
            }
            foreach (self::names($type) as $name) {
                $inputs[$name] = self::inputName($subtype, $name);
            }
        }

        foreach ($inputs as $name => $input) {
            if (array_key_exists($input, $form)) {
                $fields[$name] = $form[$input];
            }
        }

        return $fields;
    }

    /**
     * A base field some subtypes re-declare yields to them while one of them
     * is picked: hidden, and excluded from validation and from the saved
     * payload — the subtype's declaration of that name is the one in force.
     *
     * @param  list<string>  $subtypes
     */
    private static function baseField(Field $field, array $subtypes): Field
    {
        if ($subtypes === []) {
            return PrefixedField::rename($field, self::inputName(null, $field->name));
        }

        $renamed = PrefixedField::rename($field, self::inputName(null, $field->name), excludeRule: 'exclude_if:subtype,' . implode(',', $subtypes));
        $own = $renamed->toNode()->meta['hiddenIf'] ?? null;
        $yields = Cond::in('subtype', $subtypes);

        return $renamed->hiddenIf($own instanceof Cond ? Cond::any($own, $yields) : $yields);
    }

    /** hiddenIf is client-only: the server would still require a hidden subtype's fields. */
    private static function subtypeField(Field $field, string $subtype): Field
    {
        return PrefixedField::rename($field, self::inputName($subtype, $field->name), requiredRule: "required_if:subtype,{$subtype}");
    }

    /**
     * Base field name => the subtype keys whose fields() declare it again.
     *
     * @param  list<array{PageTypeInterface, ?string}>  $sections
     * @return array<string, list<string>>
     */
    private static function redeclaredBy(array $sections): array
    {
        $baseNames = [];
        $redeclaredBy = [];
        foreach ($sections as [$type, $subtype]) {
            if ($subtype === null) {
                $baseNames = self::names($type);

                continue;
            }
            foreach (array_intersect(self::names($type), $baseNames) as $name) {
                $redeclaredBy[$name][] = $subtype;
            }
        }

        return $redeclaredBy;
    }

    /**
     * The page's base type, then each subtype the "Subtype" select offers for
     * it — plus the stored subtype when registered but not offered — paired
     * with the key that picks it.
     *
     * @return list<array{PageTypeInterface, ?string}>
     */
    private static function sections(Page $page): array
    {
        $registry = app(PageTypeRegistry::class);
        $sections = $registry->has($page->type) ? ['' => [$registry->get($page->type), null]] : [];

        $subtypes = array_filter($registry->subtypesOf($page->type), static fn (PageTypeInterface $type): bool => ! $type->isSourceBacked());
        if (is_string($page->subtype) && $page->subtype !== '' && $registry->has($page->subtype)) {
            $subtypes[] = $registry->get($page->subtype);
        }

        foreach ($subtypes as $subtype) {
            $sections[$subtype->key()] ??= [$subtype, $subtype->key()];
        }

        return array_values($sections);
    }

    private static function inputName(?string $subtype, string $name): string
    {
        return $subtype === null ? self::PREFIX . $name : self::PREFIX . "{$subtype}__{$name}";
    }

    /** @return list<string> */
    private static function names(PageTypeInterface $type): array
    {
        return array_map(static fn (Field $field): string => $field->name, $type->fields(new S));
    }
}
