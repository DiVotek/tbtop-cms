<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Illuminate\Validation\Rule;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Fields\Select;
use Tbtop\Admin\Dsl\RuleWalker;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Richtext\RichtextHtmlRenderer;
use Tbtop\Cms\Richtext\RichtextHtmlToNodes;

/**
 * A page type's own fields (PageTypeInterface::fields()) as the API sees
 * them: the base type's, then the subtype's, a name both declare being the
 * subtype's — the fold the edit form does. The description and the rules
 * both come from the same mapped list, and the rules start from the type's
 * own (RuleWalker), so the API refuses what the admin form refuses.
 */
final class TypeFields
{
    private const array VALUE_KINDS = ['text', 'textarea', 'richtext', 'tags', 'select', 'media', 'repeater'];

    /**
     * @param  list<Field>  $fields  The supported DSL fields, in order.
     * @param  array<string, ApiField>  $byKey
     */
    private function __construct(private readonly array $fields, private readonly array $byKey) {}

    /** @param  list<PageTypeInterface>  $types  Base type first, then its subtype. */
    public static function of(array $types): self
    {
        $declared = [];
        foreach ($types as $type) {
            foreach (RuleWalker::fields($type->fields(new S)) as $field) {
                $declared[$field->name] = $field;
            }
        }

        $fields = [];
        $byKey = [];
        foreach ($declared as $name => $field) {
            $api = self::toApi($field);
            if ($api !== null) {
                $fields[] = $field;
                $byKey[$name] = $api;
            }
        }

        return new self($fields, $byKey);
    }

    /** @return list<ApiField> */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /** @return array<string, list<mixed>> */
    public function rules(string $prefix): array
    {
        $rules = ['fields' => $this->byKey === [] ? ['array', 'size:0'] : ['array:' . implode(',', array_keys($this->byKey))]];
        foreach (RuleWalker::collect($this->fields, $prefix) as $key => $entries) {
            $rules[$key] = $entries;
        }
        foreach ($this->byKey as $api) {
            foreach (self::valueRules($api, $prefix . $api->key) as $key => $entries) {
                $rules[$key] = [...($rules[$key] ?? []), ...$entries];
            }
        }

        return $rules;
    }

    /**
     * The stored `fields` with the validated input laid over them: a field the
     * input does not name keeps its value, a translatable one keeps the locales
     * the input does not name. Richtext arrives as HTML and is stored as nodes.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function overlay(array $stored, array $input): array
    {
        foreach ($input as $key => $value) {
            $api = $this->byKey[$key] ?? null;
            if ($api === null) {
                continue;
            }
            $stored[$key] = $api->isTranslatable && is_array($value)
                ? [...(is_array($stored[$key] ?? null) ? $stored[$key] : []), ...array_map(static fn (mixed $v): mixed => self::toStorage($api, $v), $value)]
                : self::toStorage($api, $value);
        }

        return $stored;
    }

    /**
     * Stored values as the API takes them: richtext as HTML, media ids as integers. Keys outside the
     * API's fields pass through as stored.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function present(array $stored): array
    {
        foreach ($stored as $key => $value) {
            $api = $this->byKey[$key] ?? null;
            if ($api !== null) {
                $stored[$key] = $api->isTranslatable && is_array($value)
                    ? array_map(static fn (mixed $v): mixed => self::toWire($api, $v), $value)
                    : self::toWire($api, $value);
            }
        }

        return $stored;
    }

    private static function toApi(Field $field): ?ApiField
    {
        $node = $field->toNode();
        $kind = $field instanceof Pages
            ? FieldKind::Ref
            : (in_array($node->kind, self::VALUE_KINDS, true) ? FieldKind::from($node->kind) : null);
        if ($kind === null) {
            return null;
        }

        $children = [];
        if ($kind === FieldKind::Repeater) {
            foreach (RuleWalker::fields($field->childFields()) as $child) {
                $api = self::toApi($child);
                // A row the API cannot fill whole is not offered at all.
                if ($api === null) {
                    return null;
                }
                $children[] = $api;
            }
        }

        return new ApiField(
            key: $field->name,
            label: $field->labelText() ?? $field->name,
            kind: $kind,
            isRequired: ($node->options['required'] ?? false) === true || in_array('required', $field->ruleEntries(), true),
            isTranslatable: $field->isTranslatableField(),
            isMultiple: ($node->options['multiple'] ?? false) === true,
            options: $kind === FieldKind::Select && $field instanceof Select ? self::options($node->options['options'] ?? null) : null,
            children: $children,
        );
    }

    /** @return list<array{value: string, label: string}>|null */
    private static function options(mixed $options): ?array
    {
        if (! is_array($options) || $options === []) {
            return null;
        }

        $out = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $out[] = ['value' => (string) $option['value'], 'label' => (string) ($option['label'] ?? $option['value'])];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * What the API adds on top of the type's own rules, at each value key: the
     * value's shape for its kind, a select's options, media that exists, the
     * restricted HTML of a richtext.
     *
     * @return array<string, list<mixed>>
     */
    private static function valueRules(ApiField $api, string $key): array
    {
        if (! $api->isTranslatable) {
            return self::shapeRules($api, $key);
        }

        $locales = LocaleService::contentLocales();
        $rules = [$key => ['array:' . implode(',', $locales)]];
        foreach ($locales as $locale) {
            $rules += self::shapeRules($api, "{$key}.{$locale}");
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    private static function shapeRules(ApiField $api, string $key): array
    {
        $in = $api->options === null ? [] : [Rule::in(array_column($api->options, 'value'))];
        $media = ['integer', Rule::exists(Media::class, 'id')];

        return match ($api->kind) {
            FieldKind::Text, FieldKind::Textarea => [$key => ['string']],
            FieldKind::Richtext => [$key => ['string', new RestrictedHtml]],
            FieldKind::Tags => [$key => ['array'], "{$key}.*" => ['string']],
            FieldKind::Select => $api->isMultiple ? [$key => ['array'], "{$key}.*" => $in] : [$key => $in],
            FieldKind::Media => $api->isMultiple ? [$key => ['array'], "{$key}.*" => $media] : [$key => $media],
            FieldKind::Repeater => self::repeaterRules($api, $key),
            FieldKind::Ref, FieldKind::Blocks => [],
        };
    }

    /** @return array<string, list<mixed>> */
    private static function repeaterRules(ApiField $api, string $key): array
    {
        $rules = [$key => ['array'], "{$key}.*" => ['array:' . implode(',', array_map(static fn (ApiField $c): string => $c->key, $api->children))]];
        foreach ($api->children as $child) {
            foreach (self::valueRules($child, "{$key}.*.{$child->key}") as $childKey => $entries) {
                $rules[$childKey] = $entries;
            }
        }

        return $rules;
    }

    private static function toStorage(ApiField $api, mixed $value): mixed
    {
        return match ($api->kind) {
            FieldKind::Richtext => is_string($value) && trim($value) !== '' ? app(RichtextHtmlToNodes::class)->convert($value) : null,
            FieldKind::Media => self::mediaIds($value, $api->isMultiple),
            FieldKind::Repeater => is_array($value) ? array_values(array_map(static fn (mixed $row): mixed => is_array($row) ? self::row($api, $row, self::toStorage(...)) : $row, $value)) : $value,
            default => $value,
        };
    }

    private static function toWire(ApiField $api, mixed $value): mixed
    {
        return match ($api->kind) {
            FieldKind::Richtext => $value === null ? null : app(RichtextHtmlRenderer::class)->render($value),
            FieldKind::Media => is_array($value)
                ? array_values(array_map(static fn (mixed $id): mixed => is_numeric($id) ? (int) $id : $id, $value))
                : (is_numeric($value) ? (int) $value : $value),
            FieldKind::Repeater => is_array($value) ? array_map(static fn (mixed $row): mixed => is_array($row) ? self::row($api, $row, self::toWire(...)) : $row, $value) : $value,
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  callable(ApiField, mixed): mixed  $map
     * @return array<string, mixed>
     */
    private static function row(ApiField $repeater, array $row, callable $map): array
    {
        foreach ($repeater->children as $child) {
            if (! array_key_exists($child->key, $row)) {
                continue;
            }
            $value = $row[$child->key];
            $row[$child->key] = $child->isTranslatable && is_array($value)
                ? array_map(static fn (mixed $v): mixed => $map($child, $v), $value)
                : $map($child, $value);
        }

        return $row;
    }

    /** Media ids are stored as strings, as the admin's media picker leaves them. */
    private static function mediaIds(mixed $value, bool $isMultiple): mixed
    {
        if ($isMultiple) {
            return is_array($value) ? array_values(array_map(static fn (mixed $id): string => (string) $id, $value)) : [];
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
