<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Form\Forms;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Layout\LayoutResolver;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\SharedBlocks;

class FormBlockLocator
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Languages $languages,
        private readonly LayoutResolver $layouts,
        private readonly SharedBlocks $sharedBlocks,
        private readonly Forms $forms,
    ) {}

    /**
     * Searches the page's own content first, then the active blocks of the
     * layout slots (header/footer) — a form block can live in either. With no
     * row of that id, `$blockId` is tried as a {@see Forms} name: a HasForm
     * block reachable from any page, with no editor data.
     */
    public function locate(Page $page, string $blockId, ?string $locale = null): ?LocatedFormBlock
    {
        $raw = $this->findBlock($page->content ?? [], $blockId) ?? $this->findInLayouts($blockId);
        $resolvedLocale = $locale ?? (string) app()->getLocale();

        if ($raw === null) {
            return $this->forms->has($blockId)
                ? new LocatedFormBlock($this->forms->get($blockId), [], $this->context($page, $resolvedLocale))
                : null;
        }

        $kind = (string) ($raw['kind'] ?? '');

        if (! $this->registry->has($kind)) {
            return null;
        }

        $block = $this->registry->get($kind);

        if (! $block instanceof HandlesFormSubmission) {
            return null;
        }

        // A form placed by reference is configured on the shared block, not on the row.
        $data = $this->sharedBlocks->dataOf($raw, $kind);

        if ($data === null) {
            return null;
        }

        return new LocatedFormBlock($block, $this->flatten($data, $block, $resolvedLocale), $this->context($page, $resolvedLocale));
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
        foreach ($this->layouts->content() as $content) {
            $found = $this->findBlock($content, $blockId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, Block $block, string $locale): array
    {
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
