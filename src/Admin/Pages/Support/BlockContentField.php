<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;

/**
 * Builds the Pages "Blocks" repeater and maps its rows to and from the stored
 * {id, kind, active, data} block envelope.
 *
 * v1 targets a single registered block kind: every registered block's form
 * fields are unioned into one flat repeater, so two kinds declaring the same
 * field name would collide. Per-kind field namespacing and kind-gated field
 * visibility need a polymorphic repeater primitive in the tbtop/admin engine.
 *
 * Block field names must not be `id`, `kind`, or `active`: those are the
 * envelope's reserved keys and always take precedence over row data.
 */
class BlockContentField
{
    public static function make(S $s): Node
    {
        $fields = [
            $s->text('id')->meta('hidden', true),
            $s->select('kind')->label('Block type')->required()->options(self::kindOptions()),
            $s->checkbox('active')->label('Active'),
            ...self::blockFields($s),
        ];

        return $s->section(['title' => 'Blocks'], [
            $s->repeater('content')->label('Blocks')->fields($fields),
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

            if (! app(BlockRegistry::class)->has($kind)) {
                throw ValidationException::withMessages([
                    'content' => "Block type [{$kind}] is not registered.",
                ]);
            }

            $out[] = [
                'id' => self::rowId($row),
                'kind' => $kind,
                'active' => (bool) ($row['active'] ?? true),
                'data' => Arr::except($row, ['id', 'kind', 'active']),
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
