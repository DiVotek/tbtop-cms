<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;

beforeEach(function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    $this->outputPath = sys_get_temp_dir() . '/tbtop-cms-discovery-check-' . uniqid() . '.ts';
});

afterEach(function (): void {
    if (is_file($this->outputPath)) {
        unlink($this->outputPath);
    }
});

it('reports through cms:doctor a discover entry that registers no block and one that is malformed', function (): void {
    config()->set('tbtop-cms.blocks.discover', [
        'Tbtop\\Cms\\Tests\\Fixtures\\Discovery' => __DIR__ . '/../Fixtures/Discovery',
        'App\\Wrong' => __DIR__ . '/../Fixtures/Discovery',
        'App\\Missing' => __DIR__ . '/../Fixtures/Nope',
        0 => __DIR__ . '/../Fixtures/Discovery',
    ]);

    $this->artisan('cms:doctor')
        ->expectsOutputToContain('These discover entries register no block: App\\Wrong => ' . __DIR__ . '/../Fixtures/Discovery; App\\Missing => ' . __DIR__ . '/../Fixtures/Nope. These are malformed: 0 => ' . __DIR__ . '/../Fixtures/Discovery.');

    expect(app(BlockRegistry::class)->kinds())->toBe(['Alpha', 'Beta']);
});

it('passes when every discover entry registers a block', function (): void {
    config()->set('tbtop-cms.blocks.discover', ['Tbtop\\Cms\\Tests\\Fixtures\\Discovery' => __DIR__ . '/../Fixtures/Discovery']);

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.block_discovery.ok'));
});

it('warns from cms:blocks:types about a discover entry that registers nothing, without failing', function (): void {
    config()->set('tbtop-cms.blocks.discover', [
        'App\\Wrong' => __DIR__ . '/../Fixtures/Discovery',
        0 => __DIR__ . '/../Fixtures/Discovery',
    ]);

    $this->artisan('cms:blocks:types', ['--path' => $this->outputPath])
        ->expectsOutputToContain('Discover entry [App\\Wrong => ' . __DIR__ . '/../Fixtures/Discovery] registers no block class')
        ->expectsOutputToContain('Discover entry [0 => ' . __DIR__ . '/../Fixtures/Discovery] is malformed')
        ->assertExitCode(0);
});
