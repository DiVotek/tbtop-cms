<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Api\Fields\CommonFields;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Frontend\PreviewLink;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Richtext\RichtextHtmlRenderer;

/**
 * A page as the API returns it: the model's own attributes, its texts and
 * SEO per locale with the locale's slug, its type fields, and where it lives
 * (`url`) or can be previewed while a draft (`preview_url`), both per locale.
 * Richtext comes back as HTML, the form the API takes it in.
 */
final readonly class PageResource
{
    public function __construct(
        private ApiLocales $locales,
        private PageUrl $pageUrl,
        private PreviewLink $previewLink,
        private RichtextHtmlRenderer $richtext,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(Page $page): array
    {
        $page->loadMissing('slugs');
        $seo = is_array($page->seo) ? $page->seo : [];
        $fields = is_array($page->fields) ? $page->fields : [];

        // The signed preview rides the frontend catch-all, which a host without Cms::frontendRoutes() lacks.
        $canPreview = ! $page->isLive() && Route::has('tbtop-cms.page');
        $translations = [];
        $urls = [];
        $previews = [];
        foreach ($this->locales->writable() as $locale) {
            $translations[$locale] = $this->translation($page, $seo, $locale);
            $path = $page->pathFor($locale);
            $urls[$locale] = $path === null ? null : url($this->pageUrl->build($path, $locale));
            $previews[$locale] = $path !== null && $canPreview ? $this->previewLink->for($page, $locale) : null;
        }

        return [
            'id' => (int) $page->getKey(),
            'parent_id' => $page->parent_id,
            'type' => $page->type,
            'subtype' => $page->subtype,
            'child_type' => $this->childType($page),
            'status' => $page->status->value,
            'display_state' => $page->displayState()->value,
            'published_at' => $page->published_at?->toIso8601String(),
            'unpublish_at' => $page->unpublish_at?->toIso8601String(),
            'is_indexable' => $page->is_indexable,
            'image' => self::mediaId($page->image),
            'og_image' => self::mediaId($seo['og_image'] ?? null),
            'translations' => $translations,
            'fields' => PageFields::for($page->type, $page->subtype)->typeFields->present($fields),
            'url' => $urls,
            'preview_url' => $previews,
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function translation(Page $page, array $seo, string $locale): array
    {
        $out = [];
        foreach (CommonFields::translationKeys() as $key) {
            $out[$key] = match ($key) {
                'slug' => $page->slugs->firstWhere('locale', $locale)?->slug,
                'body' => self::blankToNull($this->richtext->render($page->getTranslation('body', $locale, false))),
                'seo' => array_combine(
                    CommonFields::seoKeys(),
                    array_map(static fn (string $seoKey): ?string => self::localeValue($seo[$seoKey] ?? null, $locale), CommonFields::seoKeys()),
                ),
                default => self::text($page->getTranslation($key, $locale, false)),
            };
        }

        return $out;
    }

    /** The type the API gives a page created under this one: a category's article subtype, else null. */
    private function childType(Page $page): ?string
    {
        if ($page->type !== 'category') {
            return null;
        }

        $registry = app(PageTypeRegistry::class);
        $declared = null;
        foreach ($registry->forPage($page) as $type) {
            $declared = $type->childSubtype() ?? $declared;
        }

        return is_string($declared) && $registry->has($declared) ? $declared : 'article';
    }

    private static function localeValue(mixed $value, string $locale): ?string
    {
        $value = is_array($value) ? ($value[$locale] ?? null) : null;

        return is_string($value) ? self::blankToNull($value) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? self::blankToNull($value) : null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    private static function mediaId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
