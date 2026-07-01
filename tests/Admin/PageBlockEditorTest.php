<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('folds content rows into the stored block envelope', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [[
            'id' => '',
            'kind' => 'Hero',
            'active' => true,
            'title' => ['en' => 'Welcome'],
            'ctaHref' => 'https://example.test',
        ]],
    ]);

    $block = $page->content[0];

    expect($page->content)->toHaveCount(1)
        ->and($block['kind'])->toBe('Hero')
        ->and($block['active'])->toBeTrue()
        ->and($block['id'])->not->toBe('')
        ->and($block['data'])->toBe(['title' => ['en' => 'Welcome'], 'ctaHref' => 'https://example.test'])
        ->and($block['data'])->not->toHaveKey('kind');
});

it('preserves an existing block id across saves', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [[
            'id' => '01hzzzzzzzzzzzzzzzzzzzzzzzz',
            'kind' => 'Hero',
            'active' => true,
            'title' => ['en' => 'Welcome'],
        ]],
    ]);

    expect($page->content[0]['id'])->toBe('01hzzzzzzzzzzzzzzzzzzzzzzzz');
});

it('drops a content row that has no kind', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [
            ['id' => '', 'kind' => '', 'active' => true, 'title' => ['en' => 'Orphan']],
            ['id' => '', 'kind' => 'Hero', 'active' => true, 'title' => ['en' => 'Kept']],
        ],
    ]);

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['data']['title'])->toBe(['en' => 'Kept']);
});

it('flattens the stored envelope back into editable rows', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'About'],
        'content' => [[
            'id' => 'hero-1',
            'kind' => 'Hero',
            'active' => false,
            'data' => ['title' => ['en' => 'Welcome']],
        ]],
    ]);

    $row = PageForm::loadRow($page->id)['content'][0];

    expect($row['id'])->toBe('hero-1')
        ->and($row['kind'])->toBe('Hero')
        ->and($row['active'])->toBeFalse()
        ->and($row['title'])->toBe(['en' => 'Welcome']);
});
