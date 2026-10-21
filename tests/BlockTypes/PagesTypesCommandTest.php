<?php

declare(strict_types=1);

use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\GigPageType;
use Tbtop\Cms\Tests\Fixtures\OrphanSubtypePageType;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/tbtop-cms-pages-types-test-' . uniqid();
    mkdir($this->dir);
    $this->blocksPath = $this->dir . '/blocks.generated.ts';
    $this->pagesPath = $this->dir . '/pages.generated.ts';
    config()->set('tbtop-cms.blocks.types', []);
    PageTypeRegistry::extend(fn (): array => [EventPageType::class, ConcertPageType::class, GigPageType::class]);
});

afterEach(function (): void {
    foreach (glob($this->dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

it('writes a page variant per type and subtype next to the blocks file', function (): void {
    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])->assertExitCode(0);

    expect(file_get_contents($this->pagesPath))
        // A base type: its form's fields, subtype null.
        ->toContain("export const eventFields = z.object({\n    venue: z.string().nullable(),\n    capacity: z.number().nullable(),\n});")
        ->toContain("export const eventPage = z.object({\n    ...pageCommon,\n    type: z.literal(\"event\"),\n    subtype: z.null(),\n    fields: eventFields,\n});")
        // A subtype with a typed resolveFields(): the base's fields, then its @return keys; the base's type literal.
        ->toContain("export const gigFields = z.object({\n    venue: z.string().nullable(),\n    capacity: z.number().nullable(),\n    band: z.string().nullable(),\n    soldOut: z.boolean(),\n});")
        ->toContain("    type: z.literal(\"event\"),\n    subtype: z.literal(\"gig\"),\n    fields: gigFields,")
        ->toContain('export const cmsPage = z.union([pagePage, categoryPage, articlePage, eventPage, concertPage, gigPage]);')
        ->toContain('    concert: concertPage,');
});

it('warns about a type whose resolveFields() has no typed @return and keeps the other parts typed', function (): void {
    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])
        ->expectsOutputToContain('Page type [concert] has no typed @return array{...} on resolveFields()')
        ->assertExitCode(0);

    expect(file_get_contents($this->pagesPath))
        ->toContain("export const concertFields = z.looseObject({\n    venue: z.string().nullable(),\n    capacity: z.number().nullable(),\n});");
});

it('skips a subtype whose base is not a registered top-level type, with a warning', function (): void {
    PageTypeRegistry::extend(fn (): array => [OrphanSubtypePageType::class]);

    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])
        ->expectsOutputToContain('Page type [orphan]: base() names [ghost], which is not a registered top-level type')
        ->assertExitCode(0);

    expect(file_get_contents($this->pagesPath))->not->toContain('orphan');
});

it('fails --check without writing when only the page types file is stale', function (): void {
    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])->assertExitCode(0);
    file_put_contents($this->pagesPath, "// stale\n");

    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->blocksPath])
        ->expectsOutputToContain('pages.generated.ts is out of date')
        ->assertExitCode(1);

    expect(file_get_contents($this->pagesPath))->toBe("// stale\n");
});

it('passes --check once both files are up to date', function (): void {
    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])->assertExitCode(0);

    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->blocksPath])->assertExitCode(0);
});

it('writes the page types to the configured path, or not at all when it is false', function (): void {
    $custom = $this->dir . '/custom-pages.ts';
    config()->set('tbtop-cms.pages.types_path', $custom);
    $this->artisan('cms:blocks:types', ['--path' => $this->blocksPath])->assertExitCode(0);

    config()->set('tbtop-cms.pages.types_path', false);
    $this->artisan('cms:blocks:types', ['--check' => true, '--path' => $this->blocksPath])->assertExitCode(0);

    expect(is_file($custom))->toBeTrue()
        ->and(is_file($this->pagesPath))->toBeFalse();
});
