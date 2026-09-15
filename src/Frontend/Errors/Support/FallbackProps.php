<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Errors\Support;

use Tbtop\Cms\Page\Enums\ErrorClass;

/**
 * Props for when there is no DB error page to render (not seeded yet, or
 * unpublished) — still the normal Cms/Page component, inside the host's
 * chrome, just with nothing but a lang-file heading. Never Laravel's own
 * error view.
 */
final class FallbackProps
{
    /** @return array<string, mixed> */
    public static function build(?ErrorClass $errorClass): array
    {
        $key = ($errorClass ?? ErrorClass::ClientError)->value;

        return [
            'page' => [
                'id' => null,
                'type' => 'page',
                'status' => null,
                'name' => '',
                'title' => '',
                'heading' => __("tbtop-cms::errors.{$key}.heading"),
                'summary' => '',
                'image' => null,
            ],
            'render' => [],
            'seo' => [
                'title' => __("tbtop-cms::errors.{$key}.heading"),
                'description' => '',
                'canonical' => null,
                'noindex' => true,
                'ogUrl' => null,
            ],
            'breadcrumbs' => [],
            'path' => null,
            'isPreview' => false,
        ];
    }
}
