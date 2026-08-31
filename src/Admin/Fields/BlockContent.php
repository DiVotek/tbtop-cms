<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Fields;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;

/**
 * Builds the "Blocks" repeater and maps its rows to and from the stored
 * {id, kind, active, sort, data} block envelope. Public API: also used by
 * hosts (e.g. the storefront's template settings page) that need a block
 * editor field outside cms's own page/layout forms.
 *
 * v1 targets a single registered block kind: every registered block's form
 * fields are unioned into one flat repeater, so two kinds declaring the same
 * field name would collide. Per-kind field namespacing and kind-gated field
 * visibility need a polymorphic repeater primitive in the tbtop/admin engine.
 *
 * Block field names must not be `id`, `kind`, `active`, or `sort`: those are
 * the envelope's reserved keys and always take precedence over row data.
 * This repeater form has no `sort` field of its own (v1 has no drag-reorder
 * UI here) — toEnvelope() only preserves a `sort` a caller already placed on
 * the row (e.g. PageEditBlocks::toRows()), it never assigns one.
 */
final class BlockContent
{
    public static function make(S $s, string $name = 'content'): Node
    {
        $fields = [
            $s->text('id')->meta('hidden', true),
            $s->select('kind')->label(__('tbtop-cms::pages.blocks.type'))->required()->options(self::kindOptions()),
            $s->boolean('active')->label(__('tbtop-cms::pages.blocks.active')),
            ...self::blockFields($s),
        ];

        return $s->section(['title' => __('tbtop-cms::pages.blocks.section_title')], [
            $s->repeater($name)->label(__('tbtop-cms::pages.blocks.section_title'))->fields($fields),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toEnvelope(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['kind'] ?? '');

            if ($kind === '') {
                continue;
            }

            // A kind absent from the registry is NOT rejected: a block type
            // deregistered from the site config must not make every page
            // that still holds one unsavable. The row round-trips verbatim
            // (its data was already carried over by the caller) and simply
            // renders nothing on the frontend until re-registered.
            $entry = [
                'id' => self::rowId($row),
                'kind' => $kind,
                'active' => (bool) ($row['active'] ?? true),
            ];

            if (is_numeric($row['sort'] ?? null)) {
                $entry['sort'] = (int) $row['sort'];
            }

            $out[] = [
                ...$entry,
                'data' => Arr::except($row, ['id', 'kind', 'active', 'sort']),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toRows(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $rows = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $rows[] = [
                ...$data,
                'id' => (string) ($block['id'] ?? ''),
                'kind' => (string) ($block['kind'] ?? ''),
                'active' => (bool) ($block['active'] ?? true),
            ];
        }

        return $rows;
    }

    /** @return list<Field> */
    private static function blockFields(S $s): array
    {
        $fields = [];

        foreach (app(BlockRegistry::class)->all() as $block) {
            foreach ($block->form($s) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @return list<array{value: string, label: string}> */
    private static function kindOptions(): array
    {
        return array_map(
            static fn (string $kind): array => ['value' => $kind, 'label' => $kind],
            app(BlockRegistry::class)->kinds(),
        );
    }

    /** @param array<string, mixed> $row */
    private static function rowId(array $row): string
    {
        $id = $row['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : (string) Str::ulid();
    }
}
