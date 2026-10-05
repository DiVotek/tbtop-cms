<?php

declare(strict_types=1);

namespace Tbtop\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use RuntimeException;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\BlockTypes\BlockShape;
use Tbtop\Cms\BlockTypes\BlockShapeExtractor;
use Tbtop\Cms\BlockTypes\RegistryEmitter;
use Tbtop\Cms\BlockTypes\TypeAliasCollector;
use Tbtop\Cms\BlockTypes\TypeScriptEmitter;
use Tbtop\Cms\BlockTypes\UnsupportedShapeException;
use Tbtop\Cms\Page\TypeBlocks;

class BlocksTypesCommand extends Command
{
    protected $signature = 'cms:blocks:types
        {--check : Exit 1 when the file on disk differs from the generated output, without writing}
        {--path= : Override the output path (default: config(tbtop-cms.blocks.types_path))}';

    protected $description = 'Generate zod schemas, TS types and the catalog/component registry for registered CMS blocks.';

    public function handle(BlockRegistry $registry): int
    {
        $path = $this->outputPath();
        $registryPath = $this->registryPath($path);

        try {
            $output = $this->generate($registry);
            $registryContents = $this->generateRegistry($registry, $path, $registryPath);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($output->warnings as $warning) {
            $this->warn($warning);
        }

        if ($this->option('check')) {
            $typesOk = $this->check($path, $output->contents) === self::SUCCESS;
            $registryOk = $registryContents === null || $this->check($registryPath, $registryContents) === self::SUCCESS;

            return $typesOk && $registryOk ? self::SUCCESS : self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        if ($registryContents !== null) {
            File::ensureDirectoryExists(dirname($registryPath));
        }

        File::put($path, $output->contents);
        $this->info("Wrote {$path}");

        if ($registryContents !== null) {
            File::put($registryPath, $registryContents);
            $this->info("Wrote {$registryPath}");
        }

        return self::SUCCESS;
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

        // A component is needed where the block is drawn: wherever an editor may place it, and on the pages of a type
        // that carries it as content. A block placeable nowhere and at most General-tab fields is never rendered.
        $typeContent = app(TypeBlocks::class)->contentKinds();

        return (new RegistryEmitter)->emit(
            array_values(array_filter(
                $registry->all(),
                static fn (Block $block): bool => $block->placements() !== [] || in_array($block->kind(), $typeContent, true),
            )),
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
        $warnings = [];

        /** @var array<string, TypeNode> $aliases */
        $aliases = [];

        foreach ($registry->all() as $block) {
            $shape = $this->extractOne($extractor, $block);
            $shapes[] = $shape;

            if (! $shape->isTyped) {
                $warnings[] = "Block [{$shape->kind}] has no typed @return array{...} on resolve() — emitting a permissive fallback.";
            }

            $this->mergeAliases($aliases, (new TypeAliasCollector)->collect($block::class), $shape->kind);
        }

        $contents = (new TypeScriptEmitter)->emit($shapes, $aliases);

        return new GeneratedOutput($contents, $warnings);
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
