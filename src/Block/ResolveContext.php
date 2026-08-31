<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Richtext\RichtextHtmlRenderer;

final readonly class ResolveContext
{
    public function __construct(
        public string $locale,
        public string $fallbackLocale,
        public ?Page $page = null,
        private ?MediaUrlResolver $mediaUrlResolver = null,
        private ?RichtextHtmlRenderer $richtextHtmlRenderer = null,
        public ?object $source = null,
    ) {}

    public function mediaUrl(string | int $id): ?string
    {
        $resolver = $this->mediaUrlResolver ?? app(MediaUrlResolver::class);

        return $resolver->resolve($id);
    }

    public function richtextHtml(mixed $value): string
    {
        $renderer = $this->richtextHtmlRenderer ?? app(RichtextHtmlRenderer::class);

        return $renderer->render($value);
    }
}
