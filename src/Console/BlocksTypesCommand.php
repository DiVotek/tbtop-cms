<?php

declare(strict_types=1);

namespace Tbtop\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use RuntimeException;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockDiscovery;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\BlockTypes\BlockShape;
use Tbtop\Cms\BlockTypes\BlockShapeExtractor;
use Tbtop\Cms\BlockTypes\PageShapeExtractor;
use Tbtop\Cms\BlockTypes\PageTypeScriptEmitter;
use Tbtop\Cms\BlockTypes\RegistryEmitter;
use Tbtop\Cms\BlockTypes\TypeAliasCollector;
use Tbtop\Cms\BlockTypes\TypeScriptEmitter;
use Tbtop\Cms\BlockTypes\UnsupportedShapeException;
use Tbtop\Cms\Page\PageTypeRegistry;

class BlocksTypesCommand extends Command
{
    protected $signature = 'cms:blocks:types
        {--check : Exit 1 when the file on disk differs from the generated output, without writing}
        {--path= : Override the blocks output path (default: config(tbtop-cms.blocks.types_path)); page types go next to it unless config(tbtop-cms.pages.types_path) is set}';

    protected $description = 'Generate zod schemas, TS types and the catalog/component registry for registered CMS blocks, and the page prop types.';

    public function handle(BlockRegistry $registry, PageTypeRegistry $pageTypes): int
    {
        $path = $this->outputPath();
        $registryPath = $this->registryPath($path);
        $pagesPath = $this->pagesPath($path);

        try {
            $output = $this->generate($registry);
            $registryContents = $this->generateRegistry($registry, $path, $registryPath);
            $pages = $pagesPath === null ? null : $this->generatePages($pageTypes);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ([...$output->warnings, ...$pages->warnings ?? []] as $warning) {
            $this->warn($warning);
        }

        /** @var array<string, string> $files path => contents */
        $files = [$path => $output->contents];
        if ($registryContents !== null) {
            $files[$registryPath] = $registryContents;
        }
        if ($pagesPath !== null && $pages !== null) {
            $files[$pagesPath] = $pages->contents;
        }

        if ($this->option('check')) {
            $ok = true;
            foreach ($files as $file => $contents) {
                $ok = $this->check($file, $contents) === self::SUCCESS && $ok;
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($files as $file => $contents) {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $contents);
            $this->info("Wrote {$file}");
        }

        return self::SUCCESS;
    }

    /** Next to the blocks file by default; `false` turns the page types off. */
    private function pagesPath(string $typesPath): ?string
    {
        $configured = config('tbtop-cms.pages.types_path');

        if ($configured === false) {
            return null;
        }

        return is_string($configured) && $configured !== '' ? $configured : dirname($typesPath) . '/pages.generated.ts';
    }

    private function generatePages(PageTypeRegistry $pageTypes): GeneratedOutput
    {
        ['shapes' => $shapes, 'aliases' => $aliases, 'warnings' => $warnings] = (new PageShapeExtractor)->extract($pageTypes);

        return new GeneratedOutput((new PageTypeScriptEmitter)->emit($shapes, $aliases), $warnings);
    }

    private function check(string $path, string $contents): int
    {
        if (! File::exists($path)) {
            $this->error("{$path} does not exist. Run `php artisan cms:blocks:types` to generate it.");

            return self::FAILURE;
        }

        if (File::get($path) !== $contents) {
            $this->error("{$path} is out of date. Run `php artisan cms:blocks:types` to regenerate it.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** The registry sits next to the types file so the two import each other by bare name. */
    private function registryPath(string $typesPath): string
    {
        $configured = config('tbtop-cms.blocks.registry_path');

        return is_string($configured) && $configured !== '' ? $configured : dirname($typesPath) . '/registry.generated.ts';
    }

    /**
     * The component folder is the opt-in: a host without one (another
     * renderer, components elsewhere) gets only the types file. With the
     * folder present, every registered block must have its component there.
     * `registry_path => false` is the explicit off switch for a host that
     * has the folder but keeps its catalog and registry by hand.
     */
    private function generateRegistry(BlockRegistry $registry, string $typesPath, string $registryPath): ?string
    {
        if (config('tbtop-cms.blocks.registry_path') === false) {
            return null;
        }

        $componentsImport = config('tbtop-cms.blocks.components_dir');
        $componentsImport = is_string($componentsImport) && $componentsImport !== '' ? rtrim($componentsImport, '/') : './blocks';
        // A bare `components` would import a package by that name; the folder is always relative to the registry file.
        if (! str_starts_with($componentsImport, './') && ! str_starts_with($componentsImport, '../')) {
            $componentsImport = './' . $componentsImport;
        }
        // Collapsed by string: with `../blocks` the registry folder may not exist yet, which defeats is_dir() on the raw path.
        $componentsDir = self::collapse(dirname($registryPath) . '/' . $componentsImport);

        if (! is_dir($componentsDir)) {
            return null;
        }

        // A block placeable nowhere is never drawn, so it needs no component.
        return (new RegistryEmitter)->emit(
            array_values(array_filter($registry->all(), static fn (Block $block): bool => $block->placements() !== [])),
            self::relativeModule(dirname($registryPath), $typesPath),
            $componentsImport,
            $componentsDir,
        );
    }

    /**
     * The nearest existing ancestor goes through realpath() so a symlinked
     * segment (macOS /var → /private/var) compares equal on both sides, even
     * for a folder that is about to be created.
     */
    private static function absolute(string $path): string
    {
        $path = self::collapse(str_starts_with($path, '/') ? $path : getcwd() . '/' . $path);
        $missing = [];
        while (! is_dir($path) && $path !== '/' && $path !== '') {
            array_unshift($missing, basename($path));
            $path = dirname($path);
        }

        return implode('/', [(string) realpath($path), ...$missing]);
    }

    private static function collapse(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' && $parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
    }

    /**
     * Import specifier of a .ts file from another directory: `./x` beside it,
     * `../a/x` elsewhere. String-based on purpose — neither path need exist
     * yet when --check runs.
     */
    private static function relativeModule(string $fromDir, string $file): string
    {
        // A relative --path with an absolute registry_path (or the reverse) must compare on one base: the working directory.
        $from = explode('/', self::collapse(self::absolute($fromDir)));
        $to = explode('/', self::collapse(self::absolute(dirname($file))));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $up = $from === [] ? ['.'] : array_fill(0, count($from), '..');

        return implode('/', [...$up, ...$to, pathinfo($file, PATHINFO_FILENAME)]);
    }

    private function outputPath(): string
    {
        $override = $this->option('path');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $configured = config('tbtop-cms.blocks.types_path');

        return is_string($configured) && $configured !== '' ? $configured : resource_path('js/cms/blocks.generated.ts');
    }

    private function generate(BlockRegistry $registry): GeneratedOutput
    {
        $extractor = new BlockShapeExtractor;
        $shapes = [];
        $warnings = self::discoveryWarnings();

        /** @var array<string, TypeNode> $aliases */
        $aliases = [];

        foreach ($registry->all() as $block) {
            $shape = $this->extractOne($extractor, $block);
            $shapes[] = $shape;

            $warnings = [...$warnings, ...$shape->warnings];

            if (! $shape->isTyped) {
                $warnings[] = "Block [{$shape->kind}] has no typed @return array{...} on resolve() — emitting a permissive fallback.";
            }

            $this->mergeAliases($aliases, (new TypeAliasCollector)->collect($block::class), $shape->kind);
        }

        $contents = (new TypeScriptEmitter)->emit($shapes, $aliases);

        return new GeneratedOutput($contents, $warnings);
    }

    /** @return list<string> */
    private static function discoveryWarnings(): array
    {
        ['empty' => $empty, 'malformed' => $malformed] = BlockDiscovery::problems(config('tbtop-cms.blocks.discover', []));

        return [
            ...array_map(static fn (string $entry): string => "Discover entry [{$entry}] registers no block class — check the namespace and that the directory exists.", $empty),
            ...array_map(static fn (string $entry): string => "Discover entry [{$entry}] is malformed — expected a 'Namespace' => '/directory' pair.", $malformed),
        ];
    }

    private function extractOne(BlockShapeExtractor $extractor, Block $block): BlockShape
    {
        try {
            return $extractor->extract($block);
        } catch (UnsupportedShapeException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * Two different alias definitions sharing a name but reachable from
     * different blocks in the same registry would collide on one exported
     * const — refuse to write anything rather than pick one silently.
     * Identical shapes (by rendered string) are not a conflict.
     *
     * @param  array<string, TypeNode>  $aliases
     * @param  array<string, TypeNode>  $fromBlock
     */
    private function mergeAliases(array &$aliases, array $fromBlock, string $kind): void
    {
        foreach ($fromBlock as $name => $type) {
            if (isset($aliases[$name]) && (string) $aliases[$name] !== (string) $type) {
                throw new RuntimeException("Type alias [{$name}] is declared with two different shapes across the registry (last seen via block [{$kind}]).");
            }

            $aliases[$name] = $type;
        }
    }
}
