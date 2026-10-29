<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

class RichtextHtmlRenderer
{
    public function __construct(
        private readonly RichtextNodeRenderer $nodeRenderer = new RichtextNodeRenderer,
        private readonly RichtextHtmlToNodes $htmlToNodes = new RichtextHtmlToNodes,
    ) {}

    public function render(mixed $value): string
    {
        $root = RichtextState::root($value, $this->htmlToNodes);

        return $root === null ? '' : $this->nodeRenderer->renderChildren($root);
    }
}
