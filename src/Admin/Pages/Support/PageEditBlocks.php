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
                ...($registry->has($kind) ? self::dataFor($form, $id, $registry, $kind) : self::existingData($block)),
            ];
        }

        return $rows;
    }

    /**
     * The current envelope's block entries, guarded to well-formed rows
     * (has id + kind). Shared with PageEditBlockTabs, which needs the same
     * read for tab construction and record() prefill.
     *
     * Reads $page->content through a mixed-typed local rather than trusting
     * the model's `array<int, array<string, mixed>>` cast PHPDoc as a
     * runtime guarantee — the JSON column can hold anything written outside
     * Eloquent, so each element is still checked before use.
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
        foreach ($content as $block) {
            if (is_array($block) && isset($block['id'], $block['kind'])) {
                $blocks[] = $block;
            }
        }

        return $blocks;
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
