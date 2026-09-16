<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;

/** A minimal Block::toSchema() implementation for BlockSchemaTest. */
class FaqSchemaBlock extends Block
{
    public function kind(): string
    {
        return 'FaqTest';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->text('question')->label('Question')];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function toSchema(array $props, SchemaContext $ctx): ?array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntityOfPage' => $ctx->url,
            'mainEntity' => [[
                '@type' => 'Question',
                'name' => (string) ($props['question'] ?? ''),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Because it does.'],
            ]],
        ];
    }
}
