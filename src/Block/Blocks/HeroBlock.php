<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Blocks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

class HeroBlock extends Block
{
    public function kind(): string
    {
        return 'Hero';
    }

    #[\Override]
    public function label(): string
    {
        return 'Hero';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Full-width intro section: a headline, optional subtitle, image, and a call-to-action button.';
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
            $s->text('title')->label('Title')->translatable()->required(),
            $s->textarea('subtitle')->label('Subtitle')->translatable(),
            $s->text('image')->label('Image URL'),
            $s->text('ctaLabel')->label('CTA label')->translatable(),
            $s->text('ctaHref')->label('CTA link'),
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
            'title' => (string) ($input['title'] ?? ''),
            'subtitle' => $this->clean($input['subtitle'] ?? null),
            'image' => $this->clean($input['image'] ?? null),
            'cta' => $this->cta($input),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, href: string}|null
     */
    private function cta(array $input): ?array
    {
        $label = $this->clean($input['ctaLabel'] ?? null);
        $href = $this->clean($input['ctaHref'] ?? null);

        if ($label === null || $href === null) {
            return null;
        }

        return ['label' => $label, 'href' => $href];
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
