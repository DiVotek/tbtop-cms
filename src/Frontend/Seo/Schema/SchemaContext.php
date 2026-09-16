<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Tbtop\Cms\Page\Models\Page;

/**
 * Everything a schema class needs to build its node(s), resolved by
 * SeoResolver from the same values it uses for the rendered head tags.
 * `source` is the page type's `source()` result, null for content-only types.
 */
final readonly class SchemaContext
{
    public function __construct(
        public Page $page,
        public string $locale,
        public ?object $source,
        public string $name,
        public string $description,
        public string $url,
        public ?string $image,
    ) {}

    /**
     * datePublished is gated on isLive(), not merely on published_at being
     * set: a draft or expired page reached through admin preview must not
     * advertise a publication date for something the public cannot see.
     *
     * @return array<string, mixed>
     */
    public function node(string $type): array
    {
        $node = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $this->name,
            'url' => $this->url,
        ];

        if ($this->description !== '') {
            $node['description'] = $this->description;
        }

        if ($this->image !== null && $this->image !== '') {
            $node['image'] = $this->image;
        }

        if ($this->page->published_at !== null && $this->page->isLive()) {
            $node['datePublished'] = $this->page->published_at->toIso8601String();
        }

        if ($this->page->updated_at !== null) {
            $node['dateModified'] = $this->page->updated_at->toIso8601String();
        }

        return $node;
    }
}
