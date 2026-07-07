<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Block\ResolveContext;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('resolves a url for an existing media row', function (): void {
    $media = Media::create([
        'name' => 'photo.png',
        'disk' => 'public',
        'path' => 'media/photo.png',
        'mime' => 'image/png',
        'size' => 123,
    ]);

    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaUrl($media->getKey()))->toBe(Storage::disk('public')->url('media/photo.png'));
});

it('returns null for a missing media id', function (): void {
    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaUrl(999999))->toBeNull();
});

it('renders richtext to html via the resolve context', function (): void {
    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    $value = [
        'root' => [
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'hi', 'format' => 0]]],
            ],
        ],
    ];

    expect($ctx->richtextHtml($value))->toBe('<p>hi</p>');
});
