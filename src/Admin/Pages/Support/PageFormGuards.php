<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/** The checks PageForm::save() runs before it writes anything. */
final class PageFormGuards
{
    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException
     */
    public static function lockVersion(Page $page, array $form): void
    {
        if ((int) $form['lock_version'] !== $page->lock_version) {
            throw new PageConflictException("Page #{$page->getKey()} was changed since this form was loaded.");
        }
    }

    public static function parent(Page $page, ?int $parentId): void
    {
        if ($parentId === null || ! $page->exists) {
            return;
        }

        if ($parentId === $page->getKey() || in_array($parentId, self::descendantIds($page), true)) {
            throw ValidationException::withMessages([
                'parent_id' => __('tbtop-cms::pages.validation.parent_cycle'),
            ]);
        }
    }

    /** @param array<string, mixed> $form */
    public static function typeAndStatus(Page $page, array $form): void
    {
        if ($page->exists && $page->is_system) {
            return;
        }

        $type = (string) ($form['type'] ?? (self::isSourceBacked($page) ? $page->type : ''));
        $registry = app(PageTypeRegistry::class);

        if (! $registry->has($type)) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_unregistered', ['type' => $type]),
            ]);
        }

        // Creating a page of a source-backed type by hand, or retyping an
        // existing content-only page into one, is not allowed — the
        // storefront sync owns creation for these types (see
        // PageTypeRegistry::isSourceBacked()). A page already saved with
        // this exact type is left alone: the editor omits the type field
        // entirely for a source-backed page (PageEditSidebar), so this only
        // ever rejects a request built outside that form.
        if ($registry->isSourceBacked($type) && $type !== $page->type) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_source_backed', ['type' => $type]),
            ]);
        }

        self::status((string) ($form['status'] ?? PageStatus::Draft->value));
    }

    /** An unsaved or unregistered-type page is never source-backed. */
    public static function isSourceBacked(Page $page): bool
    {
        // getAttribute(), not ->type: callers run before fill(), where the
        // attribute is still unset despite the model's `string` docblock.
        $type = $page->getAttribute('type');
        if (! is_string($type) || $type === '') {
            return false;
        }

        $registry = app(PageTypeRegistry::class);

        return $registry->has($type) && $registry->isSourceBacked($type);
    }

    private static function status(string $status): void
    {
        if (PageStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages([
                'status' => __('tbtop-cms::pages.validation.status_invalid', ['status' => $status]),
            ]);
        }
    }

    /**
     * A schedule where the page would go dark before (or the instant) it
     * goes live is never satisfiable, so it's rejected outright rather than
     * silently producing a page that's never actually visible. Only runs
     * when both dates are present — either one alone is always valid.
     *
     * @param  array<string, mixed>  $form
     */
    public static function scheduling(array $form): void
    {
        $publishedAt = PageFormValues::nullableDateTime($form['published_at'] ?? null);
        $unpublishAt = PageFormValues::nullableDateTime($form['unpublish_at'] ?? null);

        if ($publishedAt === null || $unpublishAt === null) {
            return;
        }

        if ($unpublishAt->lessThanOrEqualTo($publishedAt)) {
            throw ValidationException::withMessages([
                'unpublish_at' => __('tbtop-cms::pages.validation.unpublish_before_published'),
            ]);
        }
    }

    /** @return list<int> */
    public static function descendantIds(Page $page): array
    {
        $ids = [];
        $frontier = [$page->getKey()];

        while ($frontier !== []) {
            $children = Page::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }
}
