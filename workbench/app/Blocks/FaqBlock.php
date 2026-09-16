<?php

declare(strict_types=1);

namespace Workbench\App\Blocks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;

/**
 * Reference Block::toSchema() implementation: an FAQ list that publishes a
 * FAQPage node alongside the page's own schema, per docs/host/ai/seo.md.
 */
class FaqBlock extends Block
{
    public function kind(): string
    {
        return 'faq';
    }

    #[\Override]
    public function label(): string
    {
        return 'FAQ';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'A list of questions and answers; publishes a FAQPage schema.org node.';
    }

    #[\Override]
    public function category(): ?string
    {
        return 'content';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [
            $s->text('heading')->label('Heading')->translatable(),
            $s->repeater('items')->label('Questions')->fields([
                $s->text('question')->label('Question')->translatable(),
                $s->textarea('answer')->label('Answer')->translatable(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return [
            'heading' => (string) ($input['heading'] ?? ''),
            'items' => self::items($input),
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function toSchema(array $props, SchemaContext $ctx): ?array
    {
        $mainEntity = [];

        /** @var array<string, mixed> $item */
        foreach ((array) ($props['items'] ?? []) as $item) {
            $question = trim((string) ($item['question'] ?? ''));

            if ($question === '') {
                continue;
            }

            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => (string) ($item['answer'] ?? ''),
                ],
            ];
        }

        if ($mainEntity === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntityOfPage' => $ctx->url,
            'mainEntity' => $mainEntity,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{question: string, answer: string}>
     */
    private static function items(array $input): array
    {
        $items = [];

        foreach ((array) ($input['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'question' => (string) ($item['question'] ?? ''),
                'answer' => (string) ($item['answer'] ?? ''),
            ];
        }

        return $items;
    }
}
