<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Tbtop\Admin\Media\MediaIngest;
use Tbtop\Admin\Media\MediaIngestException;
use Tbtop\Admin\Media\MediaResource;
use Tbtop\Cms\Api\Http\Requests\StoreMediaRequest;

/**
 * `POST /media`: one library item from a URL or an uploaded file, through
 * the admin's own ingest (MIME policy, SVG sanitising, conversions, the SSRF
 * guard on import). Refusals answer as the admin's media endpoints do.
 */
final class MediaController
{
    public function __invoke(StoreMediaRequest $request, MediaIngest $ingest): JsonResponse
    {
        $name = $request->validated('name');
        $alt = $request->validated('alt');
        $name = is_string($name) ? $name : null;
        $alt = is_string($alt) ? $alt : null;
        $file = $request->file('file');

        try {
            $media = $file instanceof UploadedFile
                ? $ingest->store($file, null, $name, $alt)
                : $ingest->import((string) $request->validated('url'), null, $name, $alt);
        } catch (MediaIngestException $e) {
            // A failed store of an uploaded file is the server's fault, as in MediaUploadController; an import reports it as a failed download, as MediaImportController does.
            abort_if($e->reason === MediaIngestException::STORE_FAILED && $file instanceof UploadedFile, 500, 'Upload failed.');
            $reason = $e->reason === MediaIngestException::STORE_FAILED ? MediaIngestException::DOWNLOAD_FAILED : $e->reason;

            return response()->json(['message' => __('tbtop-admin::admin.media.errors.' . $reason)], 422);
        }

        $item = MediaResource::toItem($media);
        $url = (string) $item['url'];

        return response()->json(['id' => $item['id'], 'url' => str_starts_with($url, '/') ? url($url) : $url], 201);
    }
}
