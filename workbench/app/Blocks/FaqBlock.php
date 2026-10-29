<?php

declare(strict_types=1);

namespace Workbench\App\Blocks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Frontend\Markdown\MarkdownContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;

/**
 * Reference Block::toSchema() and toMarkdown() implementation: an FAQ list
 * that publishes a FAQPage node alongside the page's own schema, per
 * docs/host/ai/seo.md, and reads as questions and answers in the page's `.md`.
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
     * Reference Block::toMarkdown(): the heading as H2, each question as H3
     * with its answer below, per docs/host/ai/blocks.md.
     *
     * @param  array<string, mixed>  $props
     */
    #[\Override]
    public function toMarkdown(array $props, MarkdownContext $ctx): string
    {
        $parts = [$ctx->heading((string) ($props['heading'] ?? ''))];

        /** @var array<string, mixed> $item */
        foreach ((array) ($props['items'] ?? []) as $item) {
            $question = $ctx->heading((string) ($item['question'] ?? ''), 3);

            if ($question !== '') {
                $parts[] = $question . "\n\n" . $ctx->text((string) ($item['answer'] ?? ''));
            }
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
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
