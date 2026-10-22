<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use RuntimeException;

/**
 * Assembles blocks.generated.ts: header, `import { z }`, every alias used by
 * any block (in first-use order across the registry), every block's props
 * const/type, then the `blockProps` map. Alias/block order is decided
 * entirely by BlockShapeExtractor's traversal order — the emitter only ever
 * appends, so the output is deterministic for a given registry.
 */
final class TypeScriptEmitter
{
    /**
     * @param  list<BlockShape>  $shapes  in registry order
     * @param  array<string, TypeNode>  $aliases  every alias reachable from any shape, name => type
     */
    public function emit(array $shapes, array $aliases): string
    {
        $aliasFragments = ZodModule::aliasFragments(array_map(static fn (BlockShape $shape): array => $shape->usedAliases, $shapes), $aliases);
        $this->assertDistinctExportNames($shapes, array_keys($aliasFragments));

        $lines = ZodModule::header([...array_map(static fn (BlockShape $shape): string => $shape->propsFragment, $shapes), ...array_values($aliasFragments)]);

        foreach ($aliasFragments as $name => $fragment) {
            array_push($lines, ...$this->constAndType($name, $fragment));
            $lines[] = '';
        }

        foreach ($shapes as $shape) {
            array_push($lines, ...$this->constAndType($shape->kind, $shape->propsFragment, $shape->isTyped, $shape->leadingComment));
            $lines[] = '';
        }

        $lines[] = 'export const blockProps = {';
        foreach ($shapes as $shape) {
            $lines[] = '    ' . EmitterNames::objectKey($shape->kind) . ': ' . EmitterNames::propsConst($shape->kind) . ',';
        }
        $lines[] = '} as const;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Aliases and blocks share the `<name>Props` export convention, so a
     * `@phpstan-type Hero` next to a `Hero` block would declare `heroProps` twice.
     *
     * @param  list<BlockShape>  $shapes
     * @param  list<string>  $aliasNames
     */
    private function assertDistinctExportNames(array $shapes, array $aliasNames): void
    {
        $owners = [];
        $labelled = [
            ...array_map(fn (BlockShape $shape): string => "block kind [{$shape->kind}]", $shapes),
            ...array_map(fn (string $alias): string => "type alias [{$alias}]", $aliasNames),
        ];
        $names = [...array_map(fn (BlockShape $shape): string => $shape->kind, $shapes), ...$aliasNames];

        foreach ($names as $index => $name) {
            $const = EmitterNames::propsConst($name);

            if (isset($owners[$const])) {
                throw new RuntimeException("{$owners[$const]} and {$labelled[$index]} would both be exported as `{$const}`; rename one of them.");
            }

            $owners[$const] = $labelled[$index];
        }
    }

    /** @return list<string> */
    private function constAndType(string $name, string $fragment, bool $isTyped = true, ?string $leadingComment = null): array
    {
        $const = EmitterNames::propsConst($name);
        $type = EmitterNames::propsType($name);
        $todo = $isTyped ? '' : " // TODO: add @return array{...} to {$name}::resolve()";
        $formatted = ZodFragmentFormatter::format($fragment, 0);

        $lines = $leadingComment === null ? [] : [$leadingComment];

        return [
            ...$lines,
            "export const {$const} = {$formatted};{$todo}",
            "export type {$type} = z.infer<typeof {$const}>;",
        ];
    }
}
