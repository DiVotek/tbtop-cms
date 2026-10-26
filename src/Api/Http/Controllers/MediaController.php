<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tbtop\Admin\Media\MediaIngest;
use Tbtop\Admin\Media\MediaIngestException;
use Tbtop\Admin\Media\MediaResource;
use Tbtop\Cms\Api\Http\Requests\StoreMediaRequest;

/**
 * `POST /media`: one library item imported from a public URL, through the
 * admin's own ingest (MIME policy, SVG sanitising, conversions, the SSRF
 * guard). Refusals answer as the admin's import endpoint does.
 */
final class MediaController
{
    public function __invoke(StoreMediaRequest $request, MediaIngest $ingest): JsonResponse
    {
        $name = $request->validated('name');
        $alt = $request->validated('alt');
        $name = is_string($name) ? $name : null;
        $alt = is_string($alt) ? $alt : null;

        try {
            $media = $ingest->import((string) $request->validated('url'), null, $name, $alt);
        } catch (MediaIngestException $e) {
            // A failed store is reported as a failed download, as MediaImportController does.
            $reason = $e->reason === MediaIngestException::STORE_FAILED ? MediaIngestException::DOWNLOAD_FAILED : $e->reason;

            return response()->json(['message' => __('tbtop-admin::admin.media.errors.' . $reason)], 422);
        }

        $item = MediaResource::toItem($media);
        $url = (string) $item['url'];

        return response()->json(['id' => $item['id'], 'url' => str_starts_with($url, '/') ? url($url) : $url], 201);
    }
}
