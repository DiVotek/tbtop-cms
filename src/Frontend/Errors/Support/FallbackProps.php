<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Errors\Support;

use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Settings\Settings;

/**
 * Props for when there is no DB error page to render (not seeded yet, or
 * unpublished) — still the normal Cms/Page component, inside the host's
 * chrome, just with nothing but a lang-file heading. Never Laravel's own
 * error view.
 *
 * `seo` carries every key SeoResolver::resolve() produces, empty, so the
 * frontend head renders the same contract as for a real page.
 */
final class FallbackProps
{
    /** @return array<string, mixed> */
    public static function build(?ErrorClass $errorClass): array
    {
        $key = ($errorClass ?? ErrorClass::ClientError)->value;
        $settings = app(Settings::class);
        // SeoResolver's rule with no image: an explicit card choice wins, `auto` means `summary`.
        $twitterCard = (string) $settings->get('seo.twitter_card', 'auto');

        return [
            'page' => [
                'id' => null,
                'type' => 'page',
                'subtype' => null,
                'status' => null,
                'name' => null,
                'title' => null,
                'heading' => __("tbtop-cms::errors.{$key}.heading"),
                'summary' => null,
                'body' => '',
                'image' => null,
                'fields' => (object) [],
            ],
            // An empty spec, not []: the client walks `render.elements`.
            'render' => app(PageSpecAssembler::class)->assemble([]),
            'seo' => [
                'title' => __("tbtop-cms::errors.{$key}.heading"),
                'description' => '',
                'canonical' => null,
                'ogImage' => null,
                'ogImageAlt' => null,
                'ogImageType' => null,
                'ogImageWidth' => null,
                'ogImageHeight' => null,
                'ogTitle' => '',
                'ogDescription' => '',
                'noindex' => true,
                'siteName' => (string) $settings->get('site.name', ''),
                'ogType' => 'website',
                'ogUrl' => null,
                'ogLocale' => null,
                'ogLocaleAlternates' => [],
                'articlePublishedTime' => null,
                'articleModifiedTime' => null,
                'articleAuthor' => null,
                'twitterCard' => $twitterCard === 'summary_large_image' ? $twitterCard : 'summary',
                'alternates' => [],
                'xDefault' => null,
                'ownTitle' => '',
                'ownDescription' => '',
                'favicon' => null,
                'verificationGoogle' => null,
                'verificationBing' => null,
                'jsonLd' => [],
            ],
            'breadcrumbs' => [],
            'path' => null,
            'isPreview' => false,
        ];
    }
}
