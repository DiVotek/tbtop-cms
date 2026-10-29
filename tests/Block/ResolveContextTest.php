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

/** @param array<string, array{path: string, width: int, height: int, mime: string}> $sizes */
function landscapeMedia(array $sizes, ?int $width = 2000, ?int $height = 1000): Media
{
    return Media::create([
        'name' => 'banner.png',
        'disk' => 'public',
        'path' => 'media/banner.png',
        'mime' => 'image/png',
        'size' => 689000,
        'width' => $width,
        'height' => $height,
        'alt' => 'Spring sale',
        'sizes' => $sizes,
    ]);
}

it('describes a media image with a srcset of its proportional variants, narrowest first', function (): void {
    $media = landscapeMedia([
        'thumb' => ['path' => 'media/banner-thumb.webp', 'width' => 320, 'height' => 320, 'mime' => 'image/webp'],
        'lg' => ['path' => 'media/banner-lg.webp', 'width' => 1600, 'height' => 800, 'mime' => 'image/webp'],
        'sm' => ['path' => 'media/banner-sm.webp', 'width' => 640, 'height' => 320, 'mime' => 'image/webp'],
        'md' => ['path' => 'media/banner-md.webp', 'width' => 1024, 'height' => 512, 'mime' => 'image/webp'],
    ]);
    $url = fn (string $path): string => Storage::disk('public')->url($path);

    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaImage($media->getKey()))->toBe([
        'src' => $url('media/banner.png'),
        'srcset' => $url('media/banner-sm.webp') . ' 640w, '
            . $url('media/banner-md.webp') . ' 1024w, '
            . $url('media/banner-lg.webp') . ' 1600w, '
            . $url('media/banner.png') . ' 2000w',
        'width' => 2000,
        'height' => 1000,
        'alt' => 'Spring sale',
    ]);
});

it('keeps a fit-inside thumb whose sides were rounded to whole pixels', function (): void {
    $media = landscapeMedia(
        ['thumb' => ['path' => 'media/banner-thumb.webp', 'width' => 320, 'height' => 213, 'mime' => 'image/webp']],
        1000,
        667,
    );

    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaImage($media->getKey())['srcset'] ?? null)->toBe(
        Storage::disk('public')->url('media/banner-thumb.webp') . ' 320w, '
            . Storage::disk('public')->url('media/banner.png') . ' 1000w',
    );
});

it('leaves the srcset out when the media has no responsive variant', function (): void {
    $media = landscapeMedia([
        'thumb' => ['path' => 'media/banner-thumb.webp', 'width' => 320, 'height' => 320, 'mime' => 'image/webp'],
    ]);

    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaImage($media->getKey()))->toBe([
        'src' => Storage::disk('public')->url('media/banner.png'),
        'srcset' => null,
        'width' => 2000,
        'height' => 1000,
        'alt' => 'Spring sale',
    ]);
});

it('leaves the srcset out when the original width is unknown', function (): void {
    $media = landscapeMedia(
        ['sm' => ['path' => 'media/banner-sm.webp', 'width' => 640, 'height' => 320, 'mime' => 'image/webp']],
        null,
        null,
    );

    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaImage($media->getKey()))->toMatchArray(['srcset' => null, 'width' => null]);
});

it('returns null for a missing media image id', function (): void {
    $ctx = app(ResolveContext::class, ['locale' => 'en', 'fallbackLocale' => 'en']);

    expect($ctx->mediaImage(999999))->toBeNull();
});
