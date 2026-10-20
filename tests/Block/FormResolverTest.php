<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\FormResolver\ArrayShapesBlock;
use Tbtop\Cms\Tests\Fixtures\FormResolver\EveryKindBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
});

function everyKindContext(): ResolveContext
{
    return app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);
}

it('resolves each field by its kind without a resolve() on the block', function (): void {
    $media = Media::create(['name' => 'photo.png', 'disk' => 'public', 'path' => 'media/photo.png', 'mime' => 'image/png', 'size' => 1]);
    $richtext = ['root' => ['children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'hi', 'format' => 0]]]]]];

    $props = (new EveryKindBlock)->resolve([
        'title' => '  Hello ',
        'note' => '',
        'body' => $richtext,
        'image' => $media->getKey(),
        'gallery' => [$media->getKey(), 999999, 'junk'],
        'cta' => ['type' => 'external', 'value' => 'https://example.com', 'label' => ['en' => 'Go']],
        'count' => '12',
        'level' => '3.5',
        'featured' => '1',
        'agreed' => 'on',
        'keywords' => ['a', ' b ', ''],
        'perks' => ['', 'wifi', ' parking '],
        'sizes' => ['s', 'm'],
        'size' => ' m ',
        'tone' => 'warm',
        'day' => '2026-01-02',
        'color' => '#ff0000',
        'meta' => ['k' => 'v', 'n' => 7, 'b' => true, 0 => 'dropped', 'arr' => []],
        'related' => ['999999', 'junk'],
        'items' => [
            ['label' => 'One', 'qty' => 2],
            ['label' => '', 'qty' => null],
            'not-a-row',
        ],
    ], everyKindContext());

    expect($props)->toBe([
        'title' => 'Hello',
        'note' => null,
        'body' => '<p>hi</p>',
        'image' => Storage::disk('public')->url('media/photo.png'),
        'gallery' => [Storage::disk('public')->url('media/photo.png')],
        'cta' => ['href' => 'https://example.com', 'type' => 'external', 'label' => 'Go'],
        'count' => 12,
        'level' => 3.5,
        'featured' => true,
        'agreed' => true,
        'keywords' => ['a', 'b'],
        'perks' => ['wifi', 'parking'],
        'sizes' => ['s', 'm'],
        'size' => 'm',
        'tone' => 'warm',
        'day' => '2026-01-02',
        'color' => '#ff0000',
        'meta' => ['k' => 'v', 'n' => '7', 'b' => '1'],
        'related' => [],
        'items' => [['label' => 'One', 'qty' => 2]],
    ]);
});

it('yields the empty value for malformed input of every kind', function (): void {
    $props = (new EveryKindBlock)->resolve([
        'title' => ['nested'],
        'body' => null,
        'image' => ['id' => 1],
        'gallery' => 'one',
        'cta' => 'https://not-a-link-value',
        'count' => 'twelve',
        'level' => ['3'],
        'featured' => 'no',
        'keywords' => 'a,b',
        'perks' => 'wifi',
        'size' => ['m'],
        'meta' => 'k=v',
        'related' => '12',
        'items' => 'rows',
    ], everyKindContext());

    expect($props)->toBe([
        'title' => null,
        'note' => null,
        'body' => null,
        'image' => null,
        'gallery' => [],
        'cta' => null,
        'count' => null,
        'level' => null,
        'featured' => false,
        'agreed' => false,
        'keywords' => [],
        'perks' => [],
        'sizes' => [],
        'size' => null,
        'tone' => null,
        'day' => null,
        'color' => null,
        'meta' => [],
        'related' => [],
        'items' => [],
    ]);
});

it('prints the props shape the same rules guarantee', function (): void {
    expect((new FormResolver)->phpType((new EveryKindBlock)->form(new S)))->toBe(
        'array{title: string|null, note: string|null, body: string|null, image: string|null, gallery: list<string>, '
        . 'cta: array{href: string, type: string, label: string}|null, count: float|null, level: float|null, featured: bool, agreed: bool, '
        . 'keywords: list<string>, perks: list<string>, sizes: list<string>, size: string|null, tone: string|null, day: string|null, color: string|null, meta: array<string, string>, related: list<array{id: string, name: string, url: string}>, items: list<array{label: string|null, qty: float|null}>}'
    );
});

it('resolves the array-valued field kinds instead of dropping them to null', function (): void {
    $props = (new ArrayShapesBlock)->resolve([
        'days' => ['mon', ' tue ', ''],
        'day' => ' tue ',
        'period' => ['from' => '2026-01-01', 'to' => ''],
        'files' => ['uploads/a.pdf', 'uploads/b.pdf'],
        'file' => 'uploads/c.pdf',
        'address' => ['street' => 'Main 1'],
        'sections' => [
            ['heading' => 'Intro', 'rows' => [['cell' => 'A', 'span' => '2'], ['cell' => '', 'span' => null]]],
            ['heading' => '', 'rows' => []],
        ],
    ], everyKindContext());

    expect($props)->toBe([
        'days' => ['mon', 'tue'],
        'day' => 'tue',
        'period' => ['from' => '2026-01-01', 'to' => null],
        'files' => ['uploads/a.pdf', 'uploads/b.pdf'],
        'file' => 'uploads/c.pdf',
        'address' => ['street' => 'Main 1'],
        'sections' => [['heading' => 'Intro', 'rows' => [['cell' => 'A', 'span' => 2]]]],
    ]);
});

it('resolves an emptied richtext document to null', function (): void {
    $emptied = ['root' => ['children' => [['type' => 'paragraph', 'children' => []]]]];

    $props = (new EveryKindBlock)->resolve(['body' => $emptied], everyKindContext());

    expect($props['body'])->toBeNull();
});

it('drops a repeater row whose only filled-looking value is an empty date range', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'Schedule';
        }

        /** @return list<Node> */
        public function form(S $s): array
        {
            return [
                $s->repeater('slots')->fields([
                    $s->text('label'),
                    $s->daterange('period'),
                ]),
            ];
        }
    };

    $props = $block->resolve(['slots' => [
        ['label' => 'Spring', 'period' => ['from' => '2026-03-01', 'to' => '']],
        ['label' => '', 'period' => ['from' => '', 'to' => '']],
        ['label' => ''],
    ]], everyKindContext());

    expect($props['slots'])->toBe([
        ['label' => 'Spring', 'period' => ['from' => '2026-03-01', 'to' => null]],
    ]);
});
