<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/**
 * Introspects registered blocks into a read-only display shape for the blocks
 * showcase. Everything is derived from a block's kind(), the optional metadata
 * (label/description/category), and its form() field definitions — nothing here
 * edits or persists a block.
 */
class BlockShowcase
{
    /**
     * @return array{
     *     kind: string,
     *     label: string,
     *     description: string|null,
     *     category: string|null,
     *     placements: list<string>,
     *     fields: list<array{name: string, label: string, type: string, required: bool, translatable: bool}>
     * }
     */
    public static function describe(Block $block): array
    {
        return [
            'kind' => $block->kind(),
            'label' => $block->label(),
            'description' => $block->description(),
            'category' => $block->category(),
            'placements' => $block->placements(),
            'fields' => self::fields($block),
        ];
    }

    /**
     * @return list<array{name: string, label: string, type: string, required: bool, translatable: bool}>
     */
    private static function fields(Block $block): array
    {
        return array_map(self::describeField(...), $block->form(new S));
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, translatable: bool}
     */
    private static function describeField(Field $field): array
    {
        $node = $field->toNode();
        $options = $node->options;

        return [
            'name' => $field->name,
            'label' => $field->labelText() ?? $field->name,
            'type' => $node->kind,
            'required' => ($options['required'] ?? false) === true,
            'translatable' => $field->isTranslatableField(),
        ];
    }
}
