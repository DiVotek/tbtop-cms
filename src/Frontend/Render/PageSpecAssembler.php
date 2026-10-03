<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Render;

use Illuminate\Support\Facades\Log;
use stdClass;
use Tbtop\Cms\Block\ResolvedBlock;

class PageSpecAssembler
{
    private const string ROOT_ID = '$root';

    /**
     * `errors` lists the blocks whose resolve() failed, by element id: their element is kept (so the page keeps
     * its order) with empty props, and CmsRenderer draws the placeholder in their place.
     *
     * @param  list<ResolvedBlock>  $blocks
     * @return array{root: string, elements: array<string, array<string, mixed>>, errors: array<string, string>}
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
            $elements[$block->id] = [
                'type' => $block->kind,
                'props' => $block->props === [] ? new stdClass : $block->props,
            ];
            if ($block->error !== null) {
                $errors[$block->id] = $block->error;
            }
        }

        $elements[self::ROOT_ID] = [
            'type' => $this->rootKind(),
            'props' => new stdClass,
            'children' => $children,
        ];

        return ['root' => self::ROOT_ID, 'elements' => $elements, 'errors' => $errors];
    }

    private function rootKind(): string
    {
        return (string) config('tbtop-cms.render.root_kind', 'Fragment');
    }
}
