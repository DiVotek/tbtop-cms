<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * Builds the schema.org objects rendered as `<script type="application/ld+json">`
 * tags. Kept separate from SeoResolver because the BreadcrumbList item list
 * needs the already-resolved canonical URL (the current page's own item) in
 * place of the trail's last slug-path crumb — mixing that composition into
 * SeoResolver's per-field resolve methods would blur its single job (fallback
 * chains per field).
 */
final class JsonLd
{
    /**
     * @return array{'@context': string, '@type': string, name: string, url: string}|null
     */
    public function website(string $siteName): ?array
    {
        if ($siteName === '') {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => url('/'),
        ];
    }

    /**
     * The trailing parameters are optional so a host calling this with the
     * original five keeps working: $schemaType overrides the @type derived
     * from $type (a LocalBusiness subtype), $id links the node to the
     * Article author/publisher, and $properties are further ready-built
     * properties (see SiteIdentity::properties()), placed before sameAs.
     *
     * @param  list<string>  $sameAs  Non-empty profile URLs only — callers filter blanks before calling.
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    public function identity(
        string $type,
        string $name,
        string $url,
        ?string $logo,
        array $sameAs,
        ?string $schemaType = null,
        ?string $id = null,
        array $properties = [],
    ): ?array {
        if ($type === 'none' || $name === '') {
            return null;
        }

        $object = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType ?? SiteIdentity::schemaTypeFor($type),
        ];

        if ($id !== null && $id !== '') {
            $object['@id'] = $id;
        }

        $object['name'] = $name;
        $object['url'] = $url;

        // Logo is an Organization-only property in schema.org's Person/Organization
        // vocabulary — a Person's photo would be a different property (`image`),
        // which this settings field isn't scoped to represent.
        if (in_array($type, SiteIdentity::ORGANISATION_TYPES, true) && $logo !== null && $logo !== '') {
            $object['logo'] = $logo;
        }

        foreach ($properties as $key => $value) {
            $object[$key] = $value;
        }

        if ($sameAs !== []) {
            $object['sameAs'] = $sameAs;
        }

        return $object;
    }

    /**
     * $currentUrl is the current page's already-absolute canonical URL — the list's final item.
     *
     * @param  list<array{label: string, path: string, url: string}>  $breadcrumbs  The trail preceding the page (root-relative `url`), current page excluded.
     * @return array{'@context': string, '@type': string, itemListElement: list<array{'@type': string, position: int, name: string, item: string}>}|null
     */
    public function breadcrumbList(array $breadcrumbs, string $currentLabel, string $currentUrl): ?array
    {
        $items = [
            ...array_map(
                static fn (array $ancestor): array => ['label' => $ancestor['label'], 'url' => url($ancestor['url'])],
                $breadcrumbs,
            ),
            ['label' => $currentLabel, 'url' => $currentUrl],
        ];

        if (count($items) < 2) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['label'],
                    'item' => $item['url'],
                ],
                $items,
                array_keys($items),
            ),
        ];
    }
}
