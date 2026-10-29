<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Media\Models\Media;
use Tbtop\Admin\Uploads\UploadUrl;

class MediaUrlResolver
{
    public function resolve(string | int $id): ?string
    {
        return $this->details($id)['url'] ?? null;
    }

    /**
     * Same lookup as resolve(), as what an `<img>` needs: the original as
     * `src`, a `srcset` of its proportional variants plus the original's own
     * width (null when there is none to offer), its dimensions and alt text.
     *
     * @return array{src: string, srcset: string|null, width: int|null, height: int|null, alt: string|null}|null
     */
    public function image(string | int $id): ?array
    {
        $media = $this->find($id);

        if ($media === null) {
            return null;
        }

        $src = UploadUrl::make((string) $media->disk, (string) $media->path);

        return [
            'src' => $src,
            'srcset' => MediaSrcset::build($media, $src),
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
        ];
    }

    /**
     * Same lookup as resolve(), plus the row's alt/mime/dimensions — for
     * callers that need og:image:alt / :type / :width / :height from the
     * exact media the URL came from without a second query for the same id.
     *
     * @return array{url: string, alt: string|null, mime: string, width: int|null, height: int|null}|null
     */
    public function details(string | int $id): ?array
    {
        $media = $this->find($id);

        if ($media === null) {
            return null;
        }

        return [
            'url' => UploadUrl::make((string) $media->disk, (string) $media->path),
            'alt' => $media->alt,
            'mime' => (string) $media->mime,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    private function find(string | int $id): ?Media
    {
        $media = Media::query()->find($id);

        // A row with no path would yield a bare directory URL ("/storage/"),
        // which is not an image — callers treat a non-null return as a usable
        // asset, so this has to be null instead.
        if ($media === null || trim((string) $media->path) === '') {
            return null;
        }

        return $media;
    }
}
