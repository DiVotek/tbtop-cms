<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Models\Page;

/**
 * Folds the per-block-tab form fields (`block_{id}_{field}`,
 * `block_{id}_active`) back into {@see BlockContentField::toEnvelope()}'s
 * expected row shape (`{id, kind, active, ...flatData}`) for PageEditPage,
 * so PageForm::save() folds them through the same tested envelope guard
 * (unregistered kind, id fallback) as the create form and Layout/Template
 * forms use.
 *
 * The block list/order/kind is always read from $page->content (the
 * database), never from the form — the form only carries edited field
 * values for the blocks it rendered tabs for. A block kind removed from the
 * registry after the page was saved, or a block added by another process
 * between page load and submit, round-trips unchanged: its tab (and prefix)
 * simply would not have existed to send data.
 */
final class PageEditBlocks
{
    /**
     * Rows are re-sorted by the (possibly form-edited) `sort` value before
     * returning, so BlockContentField::toEnvelope() persists the envelope
     * already ordered — PageForm::save() never needs a second sort pass.
     *
     * @param  array<string, mixed>  $form
     * @return list<array<string, mixed>>
     */
    public static function toRows(array $form, Page $page): array
    {
        $registry = app(BlockRegistry::class);
        $rows = [];

        foreach (self::blocks($page) as $block) {
            $id = (string) $block['id'];
            $kind = (string) $block['kind'];

            $rows[] = [
                'id' => $id,
                'kind' => $kind,
                'active' => self::activeFor($form, $id, $block),
                'sort' => self::sortFromForm($form, $id, $block),
                ...($registry->has($kind) ? self::dataFor($form, $id, $registry, $kind) : self::existingData($block)),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $rows;
    }

    /**
     * The current envelope's block entries, guarded to well-formed rows
     * (has id + kind), sorted by the envelope's `sort` field. Shared with
     * PageEditBlockTabs, which needs the same read for tab construction
     * (tab order) and record() prefill.
     *
     * Reads $page->content through a mixed-typed local rather than trusting
     * the model's `array<int, array<string, mixed>>` cast PHPDoc as a
     * runtime guarantee — the JSON column can hold anything written outside
     * Eloquent, so each element is still checked before use.
     *
     * Legacy blocks predating the `sort` field fall back to their array
     * index, so existing pages keep their current order until re-saved.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(Page $page): array
    {
        /** @var mixed $content */
        $content = $page->getAttribute('content');

        if (! is_array($content)) {
            return [];
        }

        $blocks = [];
        $index = 0;
        foreach ($content as $block) {
            if (is_array($block) && isset($block['id'], $block['kind'])) {
                $block['sort'] = self::sortFor($block, $index);
                $blocks[] = $block;
            }
            $index++;
        }

        usort($blocks, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $blocks;
    }

    /** @param  array<string, mixed>  $block */
    private static function sortFor(array $block, int $index): int
    {
        return is_int($block['sort'] ?? null) ? $block['sort'] : $index;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $block
     */
    private static function activeFor(array $form, string $id, array $block): bool
    {
        $key = "block_{$id}_active";

        return array_key_exists($key, $form) ? (bool) $form[$key] : (bool) ($block['active'] ?? true);
    }

    /**
     * Reads the tab's `block_{id}_sort` number field. Missing or non-numeric
     * input (blank string, cleared field) falls back to the block's prior
     * sort rather than corrupting the order.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $block
     */
    private static function sortFromForm(array $form, string $id, array $block): int
    {
        $key = "block_{$id}_sort";
        $value = $form[$key] ?? null;

        return is_numeric($value) ? (int) $value : (int) $block['sort'];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function dataFor(array $form, string $id, BlockRegistry $registry, string $kind): array
    {
        $prefix = "block_{$id}_";
        $data = [];

        foreach ($registry->get($kind)->form(new S) as $field) {
            $key = $prefix . $field->name;
            if (array_key_exists($key, $form)) {
                $data[$field->name] = $form[$key];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function existingData(array $block): array
    {
        return is_array($block['data'] ?? null) ? $block['data'] : [];
    }
}
