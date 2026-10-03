<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Fields\Repeater;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\ResolveContext;

/**
 * Resolves block input by the form's field kinds, so a block without its own
 * resolve() still hands React typed props: a media id becomes a URL, a link
 * value a {href, type, label}, richtext the rendered HTML, a repeater a list
 * of resolved rows with blank rows dropped. Every field's output shape is
 * declared next to its resolution in rule(), and phpType() prints the same
 * shape as a PHPStan array type so cms:blocks:types derives the zod schema
 * from the very rules that produce the props.
 *
 * Input is untrusted editor data, flattened to the current locale already:
 * each rule checks the value's PHP type and yields null (or an empty list)
 * rather than passing a malformed value through.
 */
final class FormResolver
{
    /**
     * @param  list<mixed>  $fields  the block's form()
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $fields, array $input, ResolveContext $ctx): array
    {
        $out = [];
        foreach (S::normalizeChildren($fields) as $field) {
            if ($field instanceof Field) {
                $out[$field->name] = $this->rule($field)['resolve']($input[$field->name] ?? null, $ctx);
            }
        }

        return $out;
    }

    /** @param  list<mixed>  $fields  the block's form() */
    public function phpType(array $fields): string
    {
        $items = [];
        foreach (S::normalizeChildren($fields) as $field) {
            if ($field instanceof Field) {
                $items[] = $field->name . ': ' . $this->rule($field)['type'];
            }
        }

        return 'array{' . implode(', ', $items) . '}';
    }

    /** @return array{type: string, resolve: callable(mixed, ResolveContext): mixed} */
    private function rule(Field $field): array
    {
        $node = $field->toNode();
        $kind = $node->kind;
        $isMultiple = ($node->options['multiple'] ?? false) === true;

        return match (true) {
            $field instanceof Repeater => [
                'type' => 'list<' . $this->phpType($field->childFields()) . '>',
                'resolve' => fn (mixed $v, ResolveContext $ctx): array => $this->rows($field->childFields(), $v, $ctx),
            ],
            $kind === 'richtext' => [
                'type' => 'string|null',
                'resolve' => static fn (mixed $v, ResolveContext $ctx): ?string => self::blankToNull($ctx->richtextHtml($v)),
            ],
            $kind === 'media' && $isMultiple => [
                'type' => 'list<string>',
                'resolve' => static fn (mixed $v, ResolveContext $ctx): array => array_values(array_filter(array_map(
                    static fn (mixed $id): ?string => is_string($id) || is_int($id) ? $ctx->mediaUrl($id) : null,
                    is_array($v) ? $v : [],
                ))),
            ],
            $kind === 'media' => [
                'type' => 'string|null',
                'resolve' => static fn (mixed $v, ResolveContext $ctx): ?string => is_string($v) || is_int($v) ? $ctx->mediaUrl($v) : null,
            ],
            $kind === 'link' => [
                'type' => 'array{href: string, type: string, label: string}|null',
                'resolve' => static fn (mixed $v, ResolveContext $ctx): ?array => $ctx->link($v),
            ],
            // `float` rather than `int|float`: the zod converter takes no union of two scalars, and both become z.number().
            $kind === 'number' || $kind === 'slider' => [
                'type' => 'float|null',
                'resolve' => static fn (mixed $v): int | float | null => is_int($v) || is_float($v) ? $v : (is_numeric($v) ? $v + 0 : null),
            ],
            $kind === 'boolean' || $kind === 'checkbox' => [
                'type' => 'bool',
                'resolve' => static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            ],
            $kind === 'checkboxlist' || $kind === 'tags' || ($kind === 'select' && $isMultiple) => [
                'type' => 'list<string>',
                'resolve' => static fn (mixed $v): array => array_values(array_filter(
                    array_map(static fn (mixed $item): ?string => is_scalar($item) ? self::blankToNull(trim((string) $item)) : null, is_array($v) ? $v : []),
                )),
            ],
            $kind === 'keyvalue' => [
                'type' => 'array<string, string>',
                'resolve' => static fn (mixed $v): array => array_filter(
                    is_array($v) ? $v : [],
                    static fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value),
                    ARRAY_FILTER_USE_BOTH,
                ),
            ],
            // text, textarea, select, radio, togglebuttons, slug, date, time, colorpicker, embed…: one trimmed string.
            default => [
                'type' => 'string|null',
                'resolve' => static fn (mixed $v): ?string => is_scalar($v) ? self::blankToNull(trim((string) $v)) : null,
            ],
        };
    }

    /**
     * @param  list<mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function rows(array $fields, mixed $value, ResolveContext $ctx): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $resolved = $this->resolve($fields, $row, $ctx);
            if (array_filter($resolved, static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== false) !== []) {
                $rows[] = $resolved;
            }
        }

        return $rows;
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
