<?php

declare(strict_types=1);

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

it('finds a translatable field inside a repeater as a dotted path', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'test';
        }

        public function form(S $s): array
        {
            return [
                $s->text('title'),
                $s->repeater('items')->fields([
                    $s->text('caption')->translatable(),
                ]),
            ];
        }
    };

    expect($block->translatableFields())->toBe(['items.caption']);
});

it('finds translatable fields at arbitrary nesting depth', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'test';
        }

        public function form(S $s): array
        {
            return [
                $s->repeater('items')->fields([
                    $s->repeater('gallery')->fields([
                        $s->text('caption')->translatable(),
                    ]),
                ]),
            ];
        }
    };

    expect($block->translatableFields())->toBe(['items.gallery.caption']);
});

it('does not let a translatable nested field collide with a same-named top-level field', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'test';
        }

        public function form(S $s): array
        {
            return [
                $s->text('title'),
                $s->repeater('items')->fields([
                    $s->text('title')->translatable(),
                ]),
            ];
        }
    };

    expect($block->translatableFields())->toBe(['items.title']);
});

it('lists top-level and nested translatable fields together', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'test';
        }

        public function form(S $s): array
        {
            return [
                $s->text('title')->translatable(),
                $s->repeater('items')->fields([
                    $s->text('caption')->translatable(),
                    $s->text('url'),
                ]),
            ];
        }
    };

    expect($block->translatableFields())->toBe(['title', 'items.caption']);
});
