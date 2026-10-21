<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Facades\Cms;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Types\ArticleType;

/**
 * An article subtype that lists the specialists referencing it through their
 * `services` field, as links and as cards carrying the specialist's own field.
 */
class LegalServicePageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'legal_service';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{specialists: list<array{id: string, name: string, url: string}>, specialistCards: list<array{name: string, bio: string|null}>}
     */
    #[\Override]
    public function resolveFields(array $input, ResolveContext $ctx): array
    {
        return [
            'specialists' => Cms::referencedByLinks($ctx->page, 'services', 'specialist'),
            'specialistCards' => array_map(static fn (Page $page): array => [
                'name' => (string) $page->getTranslation('name', $ctx->locale),
                'bio' => is_string($page->fields['bio'] ?? null) ? $page->fields['bio'] : null,
            ], Cms::referencedBy($ctx->page, 'services', 'specialist')),
        ];
    }
}
