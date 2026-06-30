<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Template\TemplateApplier;

uses(RefreshDatabase::class);

it('appends template blocks to a page with fresh ids, preserving kind, data and order', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [
            ['id' => 't1', 'kind' => 'Hero', 'data' => ['title' => 'First']],
            ['id' => 't2', 'kind' => 'Hero', 'data' => ['title' => 'Second']],
        ],
    ]);
    $page = Page::factory()->create([
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'data' => ['title' => 'Existing']]],
    ]);

    (new TemplateApplier)->applyTo($template, $page);
    $page->refresh();
    $content = $page->content;

    expect($content)->toHaveCount(3)
        ->and($content[0]['id'])->toBe('p1')
        ->and($content[1]['kind'])->toBe('Hero')
        ->and($content[1]['data']['title'])->toBe('First')
        ->and($content[2]['data']['title'])->toBe('Second')
        ->and($content[1]['id'])->not->toBe('t1')
        ->and($content[2]['id'])->not->toBe('t2')
        ->and($content[1]['id'])->not->toBe($content[2]['id']);
});

it('copies blocks independently so editing the template never changes the page', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [['id' => 't1', 'kind' => 'Hero', 'data' => ['title' => 'Original']]],
    ]);
    $page = Page::factory()->create(['content' => []]);

    (new TemplateApplier)->applyTo($template, $page);

    $template->update(['content' => [['id' => 't1', 'kind' => 'Hero', 'data' => ['title' => 'Changed']]]]);
    $page->refresh();

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['data']['title'])->toBe('Original');
});

it('generates unique ids when the same template is applied twice', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [
            ['id' => 't1', 'kind' => 'Hero', 'data' => ['title' => 'A']],
            ['id' => 't2', 'kind' => 'Hero', 'data' => ['title' => 'B']],
        ],
    ]);
    $page = Page::factory()->create(['content' => []]);

    $applier = new TemplateApplier;
    $applier->applyTo($template, $page);
    $applier->applyTo($template, $page);
    $page->refresh();

    $ids = array_column($page->content, 'id');

    expect($page->content)->toHaveCount(4)
        ->and(array_unique($ids))->toHaveCount(4);
});
