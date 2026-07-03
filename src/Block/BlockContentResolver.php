<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

class BlockContentResolver
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Languages $languages,
    ) {}

    /**
     * @param  array<int, mixed>  $content
     * @return list<ResolvedBlock>
     */
    public function resolve(array $content, ?Page $page = null): array
    {
        $locale = app()->getLocale();
        $fallback = $this->languages->defaultLocale();
        $locales = array_column($this->languages->all(), 'locale');
        $ctx = new ResolveContext($locale, $fallback, $page);

        $resolved = [];
        foreach ($content as $raw) {
            $block = $this->resolveOne($raw, $ctx, $locales);
            if ($block instanceof ResolvedBlock) {
                $resolved[] = $block;
            }
        }

        return $resolved;
    }

    /** @param list<string> $locales */
    private function resolveOne(mixed $raw, ResolveContext $ctx, array $locales): ?ResolvedBlock
    {
        if (! is_array($raw) || ! is_string($raw['kind'] ?? null) || ! is_string($raw['id'] ?? null)) {
            return null;
        }

        if (($raw['active'] ?? true) === false) {
            return null;
        }

        $kind = $raw['kind'];
        if (! $this->registry->has($kind)) {
            Log::warning('block.unknown', ['kind' => $kind]);

            return null;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $block = $this->registry->get($kind);

        try {
            $flat = LocaleFlattener::flattenData(
                $data,
                $ctx->locale,
                $ctx->fallbackLocale,
                $locales,
                $block->translatableFields(),
            );
            $props = $block->resolve($flat, $ctx);
        } catch (Throwable $e) {
            Log::warning('block.resolve_failed', ['kind' => $kind, 'id' => $raw['id'], 'error' => $e->getMessage()]);

            return null;
        }

        return new ResolvedBlock($raw['id'], $kind, $props);
    }
}
