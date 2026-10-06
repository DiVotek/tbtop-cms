<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser as VendorPhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionMethod;
use RuntimeException;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\Support\FormResolver;

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
        $returnType = $this->formType($block) ?? $this->returnTypeOf($block);

        // No docblock, `array<string, mixed>`, bare `array`, or any other
        // non-`array{}` top-level type all degrade to the untyped fallback
        // rather than error — only a construct *inside* an otherwise-usable
        // array{} shape is a hard failure.
        if ($returnType === null || ! $returnType instanceof ArrayShapeNode) {
            return $this->untypedShape($kind, $block);
        }

        $aliases = (new TypeAliasCollector)->collect($block::class);
        $converter = new ZodConverter($aliases);

        try {
            [$fragment, $leadingComment] = $this->convertTopLevelShape($converter, $returnType, $block, $kind);
        } catch (UnsupportedShapeException $e) {
            throw $e->withBlockKind($kind);
        }

        return new BlockShape($kind, $fragment, isTyped: true, usedAliases: $converter->usedAliases(), leadingComment: $leadingComment, warnings: $this->unknownFieldWarnings($block));
    }

    /** @return array{0: string, 1: ?string} the props fragment, and a leading comment to print above its `export const` */
    private function convertTopLevelShape(ZodConverter $converter, ArrayShapeNode $type, Block $block, string $kind): array
    {
        $scope = (new ReflectionMethod($block, 'resolve'))->getDeclaringClass();
        $resolved = ConstFetchResolver::resolveShape($type, $block::class, $scope);

        if (! $block instanceof HandlesFormSubmission) {
            $fields = $converter->convertShapeFields($resolved, '');

            return [$converter->objectFromFields($fields, $resolved, ''), null];
        }

        $this->assertNoFormKeyCollision($resolved, $kind);
        $fields = $converter->convertShapeFields($resolved, '');

        return [$converter->objectFromFields($this->appendFormKeys($fields), $resolved, ''), '// action/honeypotField are injected by FormActionBinder after resolve() runs'];
    }

    private function assertNoFormKeyCollision(ArrayShapeNode $type, string $kind): void
    {
        foreach ($type->items as $item) {
            $key = $item->keyName instanceof ConstExprStringNode ? $item->keyName->value : (string) $item->keyName;

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
     * @return list<string>
     */
    private function appendFormKeys(array $fields): array
    {
        $fields[] = 'action: z.string()';
        $fields[] = 'honeypotField: z.string().optional()';

        return $fields;
    }

    private function untypedShape(string $kind, Block $block): BlockShape
    {
        $fragment = $block instanceof HandlesFormSubmission
            ? ZodConverter::acceptEmptyArray('z.object({ ' . implode(', ', $this->appendFormKeys([])) . ' }).catchall(z.unknown())')
            : ZodConverter::acceptEmptyArray('z.record(z.string(), z.unknown())');

        return new BlockShape($kind, $fragment, isTyped: false, usedAliases: []);
    }

    /**
     * A block that keeps the base resolve() gets its shape from the form,
     * printed as the array type FormResolver guarantees and parsed like a
     * docblock would be — one rule set for the props and their schema. Checked
     * before the docblock: the base method's own `array<string, mixed>` would
     * otherwise win and degrade the block to the untyped fallback.
     */
    private function formType(Block $block): ?TypeNode
    {
        if ((new ReflectionMethod($block, 'resolve'))->getDeclaringClass()->getName() !== Block::class) {
            return null;
        }

        $type = (new FormResolver)->phpType($block->form(new S));

        return $this->parser->parse(new TokenIterator($this->lexer->tokenize("/** @return {$type} */")))->getReturnTagValues()[0]->type ?? null;
    }

    /** @return list<string> */
    private function unknownFieldWarnings(Block $block): array
    {
        if ((new ReflectionMethod($block, 'resolve'))->getDeclaringClass()->getName() !== Block::class) {
            return [];
        }

        return array_map(
            static fn (array $field): string => "Block [{$block->kind()}]: field [{$field['name']}] is a {$field['shape']} — its props are typed as unknown.",
            (new FormResolver)->unknownFields($block->form(new S)),
        );
    }

    private function returnTypeOf(Block $block): ?TypeNode
    {
        $reflection = new ReflectionMethod($block, 'resolve');
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        try {
            $doc = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (ParserException) {
            return null;
        }

        $returns = $doc->getReturnTagValues();

        if ($returns === []) {
            return null;
        }

        return $returns[0]->type;
    }
}
