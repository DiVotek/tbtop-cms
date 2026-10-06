<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Render;

use Illuminate\Support\Facades\Log;
use stdClass;
use Tbtop\Cms\Block\ResolvedBlock;

class PageSpecAssembler
{
    private const string ROOT_ID = '$root';

    /** Same string as BLOCK_ERROR_KIND in resources/js/cms/blockErrors.ts. Not a kind a host registers. */
    public const string BLOCK_ERROR_KIND = 'CmsBlockError';

    /**
     * A block that failed to resolve keeps its place (so the page keeps its order) as a `CmsBlockError`
     * element with props `{kind, message}` — a bare json-render Renderer then never draws the original
     * component with empty props, and CmsRenderer draws the placeholder. `errors` repeats the message by
     * element id for a host that reads it directly. Always a JSON object,
     * empty included: an empty PHP array, or ids "0", "1"…, would serialise as a list.
     *
     * @param  list<ResolvedBlock>  $blocks
     * @return array{root: string, elements: array<string, array<string, mixed>>, errors: stdClass}
     */
    public function assemble(array $blocks): array
    {
        $children = [];
        $elements = [];
        $errors = [];

        foreach ($blocks as $block) {
            if ($block->id === self::ROOT_ID || isset($elements[$block->id])) {
                Log::warning('block.reserved_or_duplicate_id', ['id' => $block->id, 'kind' => $block->kind]);

                continue;
            }

            $children[] = $block->id;
            if ($block->error !== null) {
                $elements[$block->id] = ['type' => self::BLOCK_ERROR_KIND, 'props' => ['kind' => $block->kind, 'message' => $block->error]];
                $errors[$block->id] = $block->error;

                continue;
            }
            $elements[$block->id] = [
                'type' => $block->kind,
                'props' => $block->props === [] ? new stdClass : $block->props,
            ];
        }

        $elements[self::ROOT_ID] = [
            'type' => $this->rootKind(),
            'props' => new stdClass,
            'children' => $children,
        ];

        return ['root' => self::ROOT_ID, 'elements' => $elements, 'errors' => (object) $errors];
    }

    private function rootKind(): string
    {
        return (string) config('tbtop-cms.render.root_kind', 'Fragment');
    }
}
