<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser as VendorPhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionMethod;
use RuntimeException;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;

/**
 * Reads one block's `resolve()` docblock and turns its `@return array{...}`
 * into a zod object fragment, or the untyped fallback when there is no
 * usable shape. `HandlesFormSubmission` blocks get the two FormActionBinder
 * keys appended.
 */
final class BlockShapeExtractor
{
    private readonly Lexer $lexer;

    private readonly VendorPhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new VendorPhpDocParser($config, $typeParser, $constExprParser);
    }

    public function extract(Block $block): BlockShape
    {
        $kind = $block->kind();
        $returnType = $this->returnTypeOf($block);

        // No docblock, `array<string, mixed>`, bare `array`, or any other
        // non-`array{}` top-level type all degrade to the untyped fallback
        // rather than error — only a construct *inside* an otherwise-usable
        // array{} shape is a hard failure.
        if ($returnType === null || ! $returnType instanceof ArrayShapeNode) {
            return $this->untypedShape($kind);
        }

        $aliases = (new TypeAliasCollector)->collect($block::class);
        $converter = new ZodConverter($aliases);

        try {
            [$fragment, $leadingComment] = $this->convertTopLevelShape($converter, $returnType, $block, $kind);
        } catch (UnsupportedShapeException $e) {
            throw $e->withBlockKind($kind);
        }

        return new BlockShape($kind, $fragment, isTyped: true, usedAliases: $converter->usedAliases(), leadingComment: $leadingComment);
    }

    /** @return array{0: string, 1: ?string} the props fragment, and a leading comment to print above its `export const` */
    private function convertTopLevelShape(ZodConverter $converter, ArrayShapeNode $type, Block $block, string $kind): array
    {
        $resolved = SelfTypeResolver::resolveShape($type, $block::class);

        if (! $block instanceof HandlesFormSubmission) {
            $fields = $converter->convertShapeFields($resolved, '');

            return ['z.object({ ' . implode(', ', $fields) . ' })', null];
        }

        $this->assertNoFormKeyCollision($resolved, $kind);
        $fields = $converter->convertShapeFields($resolved, '');

        return [$this->appendFormKeys($fields), '// action/honeypotField are injected by FormActionBinder after resolve() runs'];
    }

    private function assertNoFormKeyCollision(ArrayShapeNode $type, string $kind): void
    {
        foreach ($type->items as $item) {
            $key = (string) $item->keyName;

            if ($key === 'action' || $key === 'honeypotField') {
                throw new RuntimeException("Block [{$kind}]: resolve()'s docblock declares [{$key}], which FormActionBinder injects automatically — remove it from the @return shape.");
            }
        }
    }

    /**
     * Appends the two keys FormActionBinder injects into every
     * HandlesFormSubmission block's props after resolve() runs — never
     * part of the docblock itself.
     *
     * @param  list<string>  $fields
     */
    private function appendFormKeys(array $fields): string
    {
        $fields[] = 'action: z.string()';
        $fields[] = 'honeypotField: z.string().optional()';

        return 'z.object({ ' . implode(', ', $fields) . ' })';
    }

    private function untypedShape(string $kind): BlockShape
    {
        return new BlockShape($kind, 'z.record(z.string(), z.unknown())', isTyped: false, usedAliases: []);
    }

    private function returnTypeOf(Block $block): ?TypeNode
    {
        $reflection = new ReflectionMethod($block, 'resolve');
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $tokens = new TokenIterator($this->lexer->tokenize($docComment));
        $doc = $this->parser->parse($tokens);
        $returns = $doc->getReturnTagValues();

        if ($returns === []) {
            return null;
        }

        return $returns[0]->type;
    }
}
