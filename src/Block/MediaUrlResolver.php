<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Media\Models\Media;
use Tbtop\Admin\Uploads\UploadUrl;

class MediaUrlResolver
{
    public function resolve(string|int $id): ?string
    {
        $media = Media::query()->find($id);

        if ($media === null) {
            return null;
        }

        return UploadUrl::make((string) $media->disk, (string) $media->path);
    }
}
