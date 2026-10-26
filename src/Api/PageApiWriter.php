<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tbtop\Cms\Admin\Pages\Support\PageFormGuards;
use Tbtop\Cms\Admin\Pages\Support\PageLeafType;
use Tbtop\Cms\Api\Fields\CommonFields;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlocks;
use Tbtop\Cms\Page\Support\TypeTemplateContent;
use Tbtop\Cms\Richtext\RichtextHtmlToNodes;
use Tbtop\Cms\Support\LockedSave;
use Throwable;

/**
 * The API's writes. Unlike PageForm::save(), which takes the whole edit form
 * and empties every key it is not given, an update here lays only the sent
 * keys over the stored page. Every write is a model save, so the observers
 * still write slugs, revisions, refs, the audit entry and the cache bump.
 */
final readonly class PageApiWriter
{
    public function __construct(private ApiLocales $locales, private RichtextHtmlToNodes $htmlToNodes) {}

    /**
     * The leaf type and subtype a page created under $parentId gets, as the
     * admin's create form settles them (PageLeafType): v1 creates articles.
     *
     * @return array{type: string, subtype: ?string}
     */
    public static function resolveType(int $parentId): array
    {
        $page = new Page;
        ['type' => $type] = PageLeafType::resolve($page, PageLeafType::ARTICLE, $parentId);

        return ['type' => $type, 'subtype' => PageLeafType::defaultSubtype($page, $type, $parentId, null)];
    }

    /** @param  array<string, mixed>  $data  Validated by StorePageRequest. */
    public function create(array $data): Page
    {
        $parentId = (int) $data['parent_id'];
        ['type' => $type, 'subtype' => $subtype] = self::resolveType($parentId);

        $page = new Page;
        $page->fill(['type' => $type, 'subtype' => $subtype, 'parent_id' => $parentId, 'status' => PageStatus::Draft, 'is_indexable' => true]);
        $this->overlay($page, self::withNameFallback($data));

        // Blocks are not written through v1; a new page starts with its type's blocks, as in the admin.
        $starter = app(StarterBlocks::class)->contentFor($page);
        $page->content = $starter !== [] ? $starter : app(TypeTemplateContent::class)->contentFor($page);

        $this->save($page, null);

        return $page;
    }

    /** @param  array<string, mixed>  $data  Validated by UpdatePageRequest. */
    public function update(Page $page, array $data): Page
    {
        $this->overlay($page, $data);
        $this->save($page, null);

        return $page;
    }

    /**
     * Refuses a page already published and one missing a title in a locale
     * the site shows: the page would go live there with another locale's text.
     */
    public function publish(Page $page, ?Carbon $publishedAt): Page
    {
        if ($page->status === PageStatus::Published) {
            throw new ConflictHttpException(__('tbtop-cms::api.errors.already_published'));
        }

        $missing = [];
        foreach ($this->locales->active() as $locale) {
            $title = $page->getTranslation(CommonFields::REQUIRED, $locale, false);
            if (! is_string($title) || trim($title) === '') {
                $missing["translations.{$locale}.title"] = __('tbtop-cms::api.errors.title_missing', ['locale' => $locale]);
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        // Without a date, a page keeps the stamp of an earlier publish, and a first publish is stamped now
        // by the observer (stampFirstPublish), as the admin's publish does.
        $publishedAt ??= $page->published_at;
        PageFormGuards::scheduling(['published_at' => ($publishedAt ?? Carbon::now())->toIso8601String(), 'unpublish_at' => $page->unpublish_at?->toIso8601String()]);

        $page->status = PageStatus::Published;
        $page->published_at = $publishedAt;
        $this->save($page, 'published');

        return $page;
    }

    /** Drafts only: a published page is taken down in the admin, where its links and redirects are in view. */
    public function delete(Page $page): void
    {
        if ($page->status === PageStatus::Published) {
            throw new ConflictHttpException(__('tbtop-cms::api.errors.delete_published'));
        }

        try {
            $page->delete();
        } catch (DomainException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        event(new PageDeleted($page, forced: false));
    }

    /** @param  array<string, mixed>  $data */
    private function overlay(Page $page, array $data): void
    {
        $seo = is_array($page->seo) ? $page->seo : [];
        $slugs = [];

        foreach ((array) ($data['translations'] ?? []) as $locale => $texts) {
            $locale = (string) $locale;
            foreach ((array) $texts as $key => $value) {
                if ($key === 'slug') {
                    $slugs[$locale] = (string) $value;
                } elseif ($key === 'seo') {
                    $seo = self::overlaySeo($seo, $locale, (array) $value);
                } elseif ($key === 'body') {
                    $page->setTranslation('body', $locale, is_string($value) && trim($value) !== '' ? $this->htmlToNodes->convert($value) : null);
                } else {
                    $page->setTranslation((string) $key, $locale, $value === null ? '' : (string) $value);
                }
            }
        }

        if (array_key_exists('image', $data)) {
            $page->image = $data['image'] === null ? null : (string) $data['image'];
        }
        if (array_key_exists('og_image', $data)) {
            $seo['og_image'] = $data['og_image'] === null ? '' : (string) $data['og_image'];
        }

        if (array_key_exists('fields', $data)) {
            $stored = is_array($page->fields) ? $page->fields : [];
            $page->fields = PageFields::for($page->type, $page->subtype)->typeFields->overlay($stored, (array) $data['fields']);
        }

        if ($seo !== (is_array($page->seo) ? $page->seo : [])) {
            $page->seo = $seo;
        }
        $page->pendingSlugOverrides = $slugs;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function overlaySeo(array $seo, string $locale, array $values): array
    {
        foreach ($values as $key => $value) {
            $map = is_array($seo[$key] ?? null) ? $seo[$key] : [];
            $map[$locale] = $value === null ? '' : (string) $value;
            $seo[$key] = $map;
        }

        return $seo;
    }

    /**
     * A create without a name takes the title as the name per locale, the way
     * the admin's create form fills a blank title from the name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withNameFallback(array $data): array
    {
        foreach ((array) ($data['translations'] ?? []) as $locale => $texts) {
            $texts = (array) $texts;
            $name = $texts['name'] ?? null;
            $title = $texts['title'] ?? null;
            if ((! is_string($name) || trim($name) === '') && is_string($title) && trim($title) !== '') {
                $data['translations'][$locale]['name'] = $title;
            }
            if ((! is_string($title) || trim($title) === '') && is_string($name) && trim($name) !== '') {
                $data['translations'][$locale]['title'] = $name;
            }
        }

        return $data;
    }

    /** The observers' DomainExceptions are the model's invariants, answered as field errors like the admin does. */
    private function save(Page $page, ?string $auditEvent): void
    {
        $save = static fn (): bool => $page->save();

        try {
            LockedSave::run(
                static fn (): mixed => $auditEvent !== null ? app(AuditContext::class)->during($auditEvent, [], $save) : $save(),
                static fn (Throwable $e): PageConflictException => new PageConflictException("Page #{$page->getKey()} hit a lock while saving.", 0, $e),
            );
        } catch (PageConflictException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        } catch (DomainException $e) {
            throw ValidationException::withMessages([$page->isDirty('status') ? 'status' : 'parent_id' => $e->getMessage()]);
        }
    }
}
