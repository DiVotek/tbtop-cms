<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Media\Models\Media;
use Tbtop\Admin\Uploads\UploadUrl;

class MediaUrlResolver
{
    public function resolve(string | int $id): ?string
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

        return UploadUrl::make((string) $media->disk, (string) $media->path);
    }
}
