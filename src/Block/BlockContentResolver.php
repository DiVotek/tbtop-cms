<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Block\Support\TranslatablePaths;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\SharedBlock\SharedBlocks;
use Throwable;

class BlockContentResolver
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Languages $languages,
        private readonly MediaUrlResolver $mediaUrlResolver,
        private readonly SharedBlocks $sharedBlocks,
        private readonly PageTypeRegistry $pageTypes,
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
        return $this->resolveWithFields($content, $page, $source)['blocks'];
    }

    /**
     * The page's type fields resolve first, into `fields`, so every block
     * can read them through ResolveContext::field().
     *
     * @param  array<int, mixed>  $content
     * @return array{blocks: list<ResolvedBlock>, fields: array<string, mixed>}
     */
    public function resolveWithFields(array $content, ?Page $page = null, ?object $source = null): array
    {
        $locale = app()->getLocale();
        $fallback = $this->languages->defaultLocale();
        $locales = array_column($this->languages->all(), 'locale');
        $ctx = new ResolveContext($locale, $fallback, $page, $this->mediaUrlResolver, source: $source);
        $strict = (bool) config('tbtop-cms.blocks.strict', false);

        $fields = $page !== null ? $this->resolveFields($page, $ctx, $locales, $strict) : [];
        if ($fields !== []) {
            $ctx = new ResolveContext($locale, $fallback, $page, $this->mediaUrlResolver, source: $source, fields: $fields);
        }

        $resolved = [];
        foreach (self::sorted($content) as $raw) {
            $block = $this->resolveOne($raw, $ctx, $locales, $strict);
            if ($block instanceof ResolvedBlock) {
                $resolved[] = $block;
            }
        }

        return ['blocks' => $resolved, 'fields' => $fields];
    }

    /**
     * Base type first, then the subtype: a key both declare takes the
     * subtype's value. Every type reads the whole stored `fields` and keeps
     * only what its own form declares, so a key left behind by an earlier
     * subtype is not resolved. A type whose resolveFields() throws adds
     * nothing — the page still renders, as with a failing block.
     *
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function resolveFields(Page $page, ResolveContext $ctx, array $locales, bool $strict): array
    {
        $stored = is_array($page->fields) ? $page->fields : [];
        $fields = [];

        foreach ($this->pageTypes->forPage($page) as $type) {
            try {
                $input = LocaleFlattener::flattenData($stored, $ctx->locale, $ctx->fallbackLocale, $locales, TranslatablePaths::of($type->fields(new S)));
                $fields = [...$fields, ...$type->resolveFields($input, $ctx)];
            } catch (Throwable $e) {
                if ($strict) {
                    throw $e;
                }
                Log::warning('page.fields_resolve_failed', ['type' => $type->key(), 'page' => $page->getKey(), 'error' => $e->getMessage()]);
            }
        }

        return $fields;
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

            return new ResolvedBlock($raw['id'], $kind, [], "Block [{$kind}] is not registered.");
        }

        $data = $this->sharedBlocks->dataOf($raw, $kind);
        if ($data === null) {
            return new ResolvedBlock($raw['id'], $kind, [], self::missingSharedMessage($raw, $kind, $strict));
        }
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

            // The block stays in the list, marked: the page keeps its place and the client can show what failed,
            // instead of the block vanishing with only a log line to explain it.
            return new ResolvedBlock($raw['id'], $kind, [], self::errorMessage($kind, $e));
        }

        return new ResolvedBlock($raw['id'], $kind, $props);
    }

    /** @param  array<string, mixed>  $raw */
    private static function missingSharedMessage(array $raw, string $kind, bool $strict): string
    {
        $message = "Block [{$kind}] points at shared block #" . SharedBlocks::refOf($raw) . ', which does not exist.';
        Log::warning('block.shared_missing', ['kind' => $kind, 'id' => $raw['id'], 'shared' => SharedBlocks::refOf($raw)]);

        if ($strict) {
            throw BlockResolutionException::resolveFailed($kind, new RuntimeException($message));
        }

        return $message;
    }

    /** The exception text reaches the browser as page props, so outside debug it is kept generic. */
    private static function errorMessage(string $kind, Throwable $e): string
    {
        return (bool) config('app.debug')
            ? "Block [{$kind}] failed to resolve: " . $e->getMessage()
            : "Block [{$kind}] failed to resolve.";
    }
}
