<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\ArticleType;

/** An article subtype listing a schema key no SchemaTypeRegistry entry provides (`Service`) ahead of built-in ones. */
class ConsultingPageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'consulting';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['Service', 'NewsArticle', 'Article'];
    }
}
