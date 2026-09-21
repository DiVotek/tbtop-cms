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
use Tbtop\Cms\BlockTypes\TypeAliasCollector;
use Tbtop\Cms\BlockTypes\TypeScriptEmitter;
use Tbtop\Cms\BlockTypes\UnsupportedShapeException;

class BlocksTypesCommand extends Command
{
    protected $signature = 'cms:blocks:types
        {--check : Exit 1 when the file on disk differs from the generated output, without writing}
        {--path= : Override the output path (default: config(tbtop-cms.blocks.types_path))}';

    protected $description = 'Generate zod schemas and TS types for registered CMS blocks from their resolve() docblocks.';

    public function handle(BlockRegistry $registry): int
    {
        $path = $this->outputPath();

        try {
            $output = $this->generate($registry);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->check($path, $output);
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $output->contents);

        foreach ($output->warnings as $warning) {
            $this->warn($warning);
        }

        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }

    private function check(string $path, GeneratedOutput $output): int
    {
        foreach ($output->warnings as $warning) {
            $this->warn($warning);
        }

        if (! File::exists($path)) {
            $this->error("{$path} does not exist. Run `php artisan cms:blocks:types` to generate it.");

            return self::FAILURE;
        }

        if (File::get($path) !== $output->contents) {
            $this->error("{$path} is out of date. Run `php artisan cms:blocks:types` to regenerate it.");

            return self::FAILURE;
        }

        return self::SUCCESS;
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
     * Decision 6: two different alias definitions sharing a name but
     * reachable from different blocks in the same registry would collide on
     * one exported const — refuse to write anything rather than pick one
     * silently. Identical shapes (by rendered string) are not a conflict.
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
