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
     * Same lookup as resolve(), plus the row's alt/mime/dimensions — for
     * callers that need og:image:alt / :type / :width / :height from the
     * exact media the URL came from without a second query for the same id.
     *
     * @return array{url: string, alt: string|null, mime: string, width: int|null, height: int|null}|null
     */
    public function details(string | int $id): ?array
    {
        $media = Media::query()->find($id);

        if ($media === null) {
            return null;
        }

        // A row with no path would yield a bare directory URL ("/storage/"),
        // which is not an image — callers treat a non-null return as a usable
        // asset, so this has to be null instead.
        if (trim((string) $media->path) === '') {
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
}
