<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Tests\Fixtures\EmptyKindBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

it('registers a block from config', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);

    expect((new BlockRegistry)->has('Hero'))->toBeTrue();
});

it('returns the registered block instance', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);

    expect((new BlockRegistry)->get('Hero'))->toBeInstanceOf(HeroBlock::class);
});

it('throws for an unknown kind', function (): void {
    expect(fn () => (new BlockRegistry)->get('Nope'))->toThrow(InvalidArgumentException::class);
});

it('rejects a class that is not a block', function (): void {
    config()->set('tbtop-cms.blocks.types', [stdClass::class]);

    expect(fn () => (new BlockRegistry)->kinds())->toThrow(InvalidArgumentException::class);
});

it('rejects a duplicate kind', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, HeroBlock::class]);

    expect(fn () => (new BlockRegistry)->kinds())->toThrow(InvalidArgumentException::class);
});

it('rejects an empty kind', function (): void {
    config()->set('tbtop-cms.blocks.types', [EmptyKindBlock::class]);

    expect(fn () => (new BlockRegistry)->kinds())->toThrow(InvalidArgumentException::class);
});
