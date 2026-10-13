<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/** Maps a submitted page form onto the model's mass-assignable attributes. */
final class PageFormAttributes
{
    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function for(Page $page, array $form, ?int $parentId, bool $isCreate): array
    {
        $attributes = [
            'name' => $form['name'] ?? [],
            'title' => $form['title'] ?? [],
            'heading' => $form['heading'] ?? [],
            'summary' => $form['summary'] ?? [],
            'body' => $form['body'] ?? [],
            // Media id string, not a URL — resolved to a URL on the frontend
            // via MediaUrlResolver. Applies to system pages too, so it stays
            // in the unconditional part below rather than the is_system gate.
            'image' => PageFormValues::nullableMediaId($form['image'] ?? null),
            // The create form has no is_indexable field — new pages are always
            // indexable by default; edits keep the form-submitted value.
            'is_indexable' => $isCreate ? true : (bool) ($form['is_indexable'] ?? false),
            'sort_order' => (int) ($form['sort_order'] ?? 0),
            'seo' => self::seoAttribute($page, $form),
        ];

        if ($page->exists && $page->is_system) {
            return $attributes;
        }

        $type = (string) ($form['type'] ?? (PageFormGuards::isSourceBacked($page) ? $page->type : ''));

        return [
            ...$attributes,
            // Omitted entirely for a source-backed page (PageEditSidebar
            // hides the type select — its type is owned by the sync, not
            // editorially chosen): $type keeps the page's existing type
            // rather than reading a missing key as ''.
            'type' => $type,
            'subtype' => self::subtypeAttribute($page, $form, $type),
            'parent_id' => $parentId,
            'status' => (string) ($form['status'] ?? PageStatus::Draft->value),
            // Create form never sends these (PageEditSidebar is edit-only),
            // so they stay null until the page is first edited — matching
            // status's own create-time default.
            'published_at' => PageFormValues::nullableDateTime($form['published_at'] ?? null),
            'unpublish_at' => PageFormValues::nullableDateTime($form['unpublish_at'] ?? null),
        ];
    }

    /**
     * A subtype must be one of $type's. A missing `subtype` key keeps the
     * stored one. A type change that would leave the stored subtype behind is
     * rejected; any other value that does not fit is dropped when the type
     * changed (a stale create-form select) and rejected otherwise — except the
     * page's own stored subtype, which outlives its registration the way
     * `type` does.
     *
     * @param  array<string, mixed>  $form
     */
    private static function subtypeAttribute(Page $page, array $form, string $type): ?string
    {
        $stored = $page->getAttribute('subtype');
        $value = array_key_exists('subtype', $form) ? $form['subtype'] : $stored;
        if (! is_string($value) || $value === '') {
            return null;
        }

        $subtypes = array_map(static fn (PageTypeInterface $subtype): string => $subtype->key(), app(PageTypeRegistry::class)->subtypesOf($type));
        $isTypeKept = $page->exists && $page->type === $type;

        if (in_array($value, $subtypes, true) || ($isTypeKept && $value === $stored)) {
            return $value;
        }
        // Retyping a page would silently strip its subtype (and with it the
        // subtype's fields from the form); the editor clears it first instead.
        if ($page->exists && ! $isTypeKept && $value === $stored) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_change_subtype', ['subtype' => $stored]),
            ]);
        }
        if (! $isTypeKept) {
            return null;
        }

        throw ValidationException::withMessages([
            'subtype' => __('tbtop-cms::pages.validation.subtype_invalid', ['subtype' => $value, 'type' => $type]),
        ]);
    }

    /**
     * Create-only: when a locale's title is empty but its name is set, fall
     * the title back to the name — the create form has no heading/summary
     * but title still drives <title>/OG tags, so it should not start blank.
     * Edits never run this: an admin clearing the title on the edit page
     * means it, and re-filling it from the name would override that intent.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function withTitleFallback(array $form): array
    {
        $name = PageFormValues::stringMap($form['name'] ?? null);
        $title = PageFormValues::stringMap($form['title'] ?? null);

        foreach ($name as $locale => $value) {
            if ($value !== '' && ($title[$locale] ?? '') === '') {
                $title[$locale] = $value;
            }
        }

        $form['title'] = $title;

        return $form;
    }

    /**
     * Overlays the form-owned keys on the stored bag, so keys the form does not own (a host's) survive.
     * `schema` is written only when the form sent `schema_type`; otherwise the stored value stays.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function seoAttribute(Page $page, array $form): array
    {
        $stored = is_array($page->seo) ? $page->seo : [];

        return [
            ...$stored,
            'title' => PageFormValues::stringMap($form['seo_title'] ?? null),
            'description' => PageFormValues::stringMap($form['seo_description'] ?? null),
            'focus_keyword' => PageFormValues::stringMap($form['seo_focus_keyword'] ?? null),
            'og_title' => PageFormValues::stringMap($form['og_title'] ?? null),
            'og_description' => PageFormValues::stringMap($form['og_description'] ?? null),
            'og_image' => (string) ($form['og_image'] ?? ''),
            'canonical' => PageFormValues::stringMap($form['seo_canonical'] ?? null),
            ...(array_key_exists('schema_type', $form) ? ['schema' => ['type' => (string) $form['schema_type']]] : []),
        ];
    }
}
