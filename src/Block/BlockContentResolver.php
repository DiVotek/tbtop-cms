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
        private readonly MediaUrlResolver $mediaUrlResolver,
    ) {}

    /**
     * Sorted here, not in PageSpecAssembler: this is the single boundary
     * every consumer of the raw envelope's block order passes through (page
     * render today; any future consumer of resolve() inherits it for free),
     * and it's the one place that still has each block's `id` handy to
     * disambiguate the stable-sort fallback deterministically by original
     * position rather than by an assembler-side reindex.
     *
     * @param  array<int, mixed>  $content
     * @return list<ResolvedBlock>
     */
    public function resolve(array $content, ?Page $page = null, ?object $source = null): array
    {
        $locale = app()->getLocale();
        $fallback = $this->languages->defaultLocale();
        $locales = array_column($this->languages->all(), 'locale');
        $ctx = new ResolveContext($locale, $fallback, $page, $this->mediaUrlResolver, source: $source);
        $strict = (bool) config('tbtop-cms.blocks.strict', false);

        $resolved = [];
        foreach (self::sorted($content) as $raw) {
            $block = $this->resolveOne($raw, $ctx, $locales, $strict);
            if ($block instanceof ResolvedBlock) {
                $resolved[] = $block;
            }
        }

        return $resolved;
    }

    /**
     * Envelope blocks ordered by `sort`; blocks missing it (legacy content
     * saved before the field existed) fall back to their array index, so
     * un-migrated pages keep rendering in their current order. usort() is
     * stable in PHP >= 8.0, so equal `sort` values preserve array order.
     *
     * @param  array<int, mixed>  $content
     * @return list<mixed>
     */
    private static function sorted(array $content): array
    {
        $index = 0;
        $withSort = [];
        foreach ($content as $raw) {
            $sort = is_array($raw) && is_int($raw['sort'] ?? null) ? $raw['sort'] : $index;
            $withSort[] = [$sort, $raw];
            $index++;
        }

        usort($withSort, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_column($withSort, 1);
    }

    /** @param list<string> $locales */
    private function resolveOne(mixed $raw, ResolveContext $ctx, array $locales, bool $strict): ?ResolvedBlock
    {
        if (! is_array($raw) || ! is_string($raw['kind'] ?? null) || ! is_string($raw['id'] ?? null)) {
            return null;
        }

        if (($raw['active'] ?? true) === false) {
            return null;
        }

        $kind = $raw['kind'];
        if (! $this->registry->has($kind)) {
            if ($strict) {
                throw BlockResolutionException::unknownKind($kind);
            }

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
            if ($strict) {
                throw BlockResolutionException::resolveFailed($kind, $e);
            }

            Log::warning('block.resolve_failed', ['kind' => $kind, 'id' => $raw['id'], 'error' => $e->getMessage()]);

            return null;
        }

        return new ResolvedBlock($raw['id'], $kind, $props);
    }
}
