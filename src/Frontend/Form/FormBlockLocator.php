<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Form;

use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;

class FormBlockLocator
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Languages $languages,
    ) {}

    public function locate(Page $page, string $blockId): ?LocatedFormBlock
    {
        $raw = $this->findBlock($page->content ?? [], $blockId);

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

        return new LocatedFormBlock($block, $this->flatten($raw, $block), $this->context($page));
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

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function flatten(array $raw, Block $block): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $locales = array_column($this->languages->all(), 'locale');

        return LocaleFlattener::flattenData(
            $data,
            (string) app()->getLocale(),
            $this->languages->defaultLocale(),
            $locales,
            $block->translatableFields(),
        );
    }

    private function context(Page $page): SubmitContext
    {
        return new SubmitContext((string) app()->getLocale(), $this->languages->defaultLocale(), $page);
    }
}
