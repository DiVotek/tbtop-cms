<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Models\Page;

class FormBlockLocator
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Languages $languages,
    ) {}

    /**
     * Searches the page's own content first, then the active blocks of the
     * layout slots (header/footer) — a form block can live in either.
     */
    public function locate(Page $page, string $blockId, ?string $locale = null): ?LocatedFormBlock
    {
        $raw = $this->findBlock($page->content ?? [], $blockId) ?? $this->findInLayouts($blockId);

        if ($raw === null) {
            return null;
        }

        $kind = (string) ($raw['kind'] ?? '');

        if (! $this->registry->has($kind)) {
            return null;
        }

        $block = $this->registry->get($kind);

        if (! $block instanceof HandlesFormSubmission) {
            return null;
        }

        $resolvedLocale = $locale ?? (string) app()->getLocale();

        return new LocatedFormBlock($block, $this->flatten($raw, $block, $resolvedLocale), $this->context($page, $resolvedLocale));
    }

    /**
     * @param  array<int, mixed>  $content
     * @return array<array-key, mixed>|null
     */
    private function findBlock(array $content, string $blockId): ?array
    {
        foreach ($content as $raw) {
            if (is_array($raw) && ($raw['id'] ?? null) === $blockId && ($raw['active'] ?? true) !== false) {
                return $raw;
            }
        }

        return null;
    }

    /** @return array<array-key, mixed>|null */
    private function findInLayouts(string $blockId): ?array
    {
        if (! Schema::hasTable((new Layout)->getTable())) {
            return null;
        }

        foreach (Slots::all() as $slot) {
            $layout = Layout::query()->where('slot', $slot)->first();
            $found = $layout instanceof Layout ? $this->findBlock($layout->content ?? [], $blockId) : null;

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function flatten(array $raw, Block $block, string $locale): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $locales = array_column($this->languages->all(), 'locale');

        return LocaleFlattener::flattenData(
            $data,
            $locale,
            $this->languages->defaultLocale(),
            $locales,
            $block->translatableFields(),
        );
    }

    private function context(Page $page, string $locale): SubmitContext
    {
        return new SubmitContext($locale, $this->languages->defaultLocale(), $page);
    }
}
