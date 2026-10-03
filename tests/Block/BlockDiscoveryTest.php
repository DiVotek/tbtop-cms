<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;

it('registers the concrete Block classes of a discovered folder in path order, skipping traits and abstract classes', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    config()->set('tbtop-cms.blocks.discover', [
        'Tbtop\\Cms\\Tests\\Fixtures\\Discovery' => __DIR__ . '/../Fixtures/Discovery',
    ]);

    expect(app(BlockRegistry::class)->kinds())->toBe(['Alpha', 'Beta']);
});

it('ignores a discovered folder that does not exist', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    config()->set('tbtop-cms.blocks.discover', ['App\\Missing' => __DIR__ . '/../Fixtures/Nope']);

    expect(app(BlockRegistry::class)->kinds())->toBe([]);
});
