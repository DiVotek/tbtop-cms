<?php

declare(strict_types=1);

use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

it('rejects an unknown attribute via fill', function (): void {
    $page = new Page;
    $page->fill(['is_admin' => true]);

    expect($page->getAttributes())->not->toHaveKey('is_admin');
});

it('accepts the known fillable set via fill', function (): void {
    $page = new Page;
    $page->fill([
        'type' => 'page',
        'parent_id' => null,
        'status' => 'draft',
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About us'],
        'heading' => ['en' => 'About'],
        'summary' => ['en' => 'Summary'],
        'body' => ['en' => 'Body'],
        'image' => 'media-1',
        'content' => [],
        'seo' => [],
        'is_indexable' => true,
        'is_system' => false,
        'sort_order' => 3,
    ]);

    expect($page->type)->toBe('page')
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($page->image)->toBe('media-1')
        ->and($page->is_indexable)->toBeTrue()
        ->and($page->is_system)->toBeFalse()
        ->and($page->sort_order)->toBe(3);
});
