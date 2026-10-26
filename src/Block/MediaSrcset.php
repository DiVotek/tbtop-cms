<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Media\Models\Media;
use Tbtop\Admin\Uploads\UploadUrl;

/**
 * A `srcset` from the variants tbtop/admin stores in `Media::$sizes`.
 *
 * A candidate must be the same picture as the original, only smaller: a
 * variant whose aspect ratio differs (a square crop of a landscape banner) is
 * left out, whatever its profile name. tbtop/admin resizes "fit inside" and
 * rounds each side to whole pixels, so the ratio may drift by that rounding.
 */
final class MediaSrcset
{
    public static function build(Media $media, string $src): ?string
    {
        $width = $media->width;
        $height = $media->height;

        if ($width === null || $height === null || $width < 1 || $height < 1) {
            return null;
        }

        $candidates = [];

        foreach ($media->sizes ?? [] as $variant) {
            $candidate = self::candidate($variant, (string) $media->disk, $width, $height);

            if ($candidate !== null) {
                $candidates[$candidate['width']] ??= $candidate['url'];
            }
        }

        if ($candidates === []) {
            return null;
        }

        ksort($candidates);
        $candidates[$width] = $src;

        return implode(', ', array_map(
            static fn (int $w, string $url): string => "{$url} {$w}w",
            array_keys($candidates),
            $candidates,
        ));
    }

    /** @return array{url: string, width: int}|null */
    private static function candidate(mixed $variant, string $disk, int $width, int $height): ?array
    {
        if (! is_array($variant)) {
            return null;
        }

        $path = $variant['path'] ?? null;
        $w = $variant['width'] ?? null;
        $h = $variant['height'] ?? null;

        if (! is_string($path) || trim($path) === '' || ! is_int($w) || ! is_int($h) || $w < 1 || $w >= $width) {
            return null;
        }

        // Cross-multiplied ratios; half a pixel of rounding per side stays within max(width, height).
        if (abs($h * $width - $w * $height) > max($width, $height)) {
            return null;
        }

        return ['url' => UploadUrl::make($disk, $path), 'width' => $w];
    }
}
