<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\Page\Models\Page;

final readonly class ResolveContext
{
    public function __construct(
        public string $locale,
        public string $fallbackLocale,
        public ?Page $page = null,
        private ?MediaUrlResolver $mediaUrlResolver = null,
    ) {}

    public function mediaUrl(string | int $id): ?string
    {
        $resolver = $this->mediaUrlResolver ?? app(MediaUrlResolver::class);

        return $resolver->resolve($id);
    }
}
