<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Tests\Fixtures\EmptyKindBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

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

it('returns a block registered by an extension', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [HeroBlock::class]);

    $registry = new BlockRegistry;

    expect($registry->has('Hero'))->toBeTrue()
        ->and($registry->get('Hero'))->toBeInstanceOf(HeroBlock::class)
        ->and($registry->kinds())->toBe(['Hero'])
        ->and(array_keys($registry->all()))->toBe(['Hero']);
});

it('registers two extensions in order', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [HeroBlock::class]);
    BlockRegistry::extend(fn (): array => [SimpleBlock::class]);

    expect((new BlockRegistry)->kinds())->toBe(['Hero', 'Simple']);
});

it('rejects a duplicate kind coming from an extension', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    BlockRegistry::extend(fn (): array => [HeroBlock::class]);

    expect(fn () => (new BlockRegistry)->kinds())->toThrow(InvalidArgumentException::class);
});

it('does not invoke the extension callable until the registry is first used', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    $calls = 0;
    BlockRegistry::extend(function () use (&$calls): array {
        $calls++;

        return [HeroBlock::class];
    });

    $registry = new BlockRegistry;

    expect($calls)->toBe(0);

    $registry->kinds();

    expect($calls)->toBe(1);
});

it('does not see an extension registered before flushExtensions()', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [HeroBlock::class]);
    BlockRegistry::flushExtensions();

    expect((new BlockRegistry)->has('Hero'))->toBeFalse();
});
