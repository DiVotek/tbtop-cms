<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\TypedAliasBlock;

beforeEach(function (): void {
    $this->outputPath = sys_get_temp_dir() . '/tbtop-cms-blocks-types-check-test-' . uniqid() . '.ts';
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [TypedAliasBlock::class]);
});

afterEach(function (): void {
    if (is_file($this->outputPath)) {
        unlink($this->outputPath);
    }
});

it('exits 1 without writing when --check runs against a missing file', function (): void {
    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->outputPath])
        ->assertExitCode(1);

    expect(is_file($this->outputPath))->toBeFalse();
});

it('exits 1 without writing when --check runs against a file that differs from the generated output', function (): void {
    file_put_contents($this->outputPath, "// stale content\n");

    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->outputPath])
        ->assertExitCode(1);

    expect(file_get_contents($this->outputPath))->toBe("// stale content\n");
});

it('exits 0 without rewriting when --check runs against an already up-to-date file', function (): void {
    $this->artisan('cms:blocks:types', ['--path' => $this->outputPath])->assertExitCode(0);
    $generated = file_get_contents($this->outputPath);

    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->outputPath])
        ->assertExitCode(0);

    expect(file_get_contents($this->outputPath))->toBe($generated);
});
