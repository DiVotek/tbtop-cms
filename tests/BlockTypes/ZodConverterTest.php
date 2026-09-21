<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Tbtop\Cms\BlockTypes\UnsupportedShapeException;
use Tbtop\Cms\BlockTypes\ZodConverter;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\ValueOfConstFixture;

/**
 * Parses a bare PHPDoc type string (no surrounding /** @return) into the same
 * TypeNode ZodConverter consumes in production, so each case below exercises
 * the real vendor parser rather than hand-built AST fixtures.
 */
function parsePhpDocType(string $type): TypeNode
{
    $config = new ParserConfig(usedAttributes: []);
    $lexer = new Lexer($config);
    $constExprParser = new ConstExprParser($config);
    $typeParser = new TypeParser($config, $constExprParser);

    $tokens = new TokenIterator($lexer->tokenize($type));

    return $typeParser->parse($tokens);
}

/** One row per PHPDoc construct ZodConverter maps; each expected fragment is a literal, never recomputed through the converter itself. */
dataset('mappingTableCases', function (): array {
    return [
        'string' => ['string', 'z.string()'],
        'non-empty-string' => ['non-empty-string', 'z.string().min(1)'],
        'int' => ['int', 'z.number().int()'],
        'positive-int' => ['positive-int', 'z.number().int().positive()'],
        'int<0,100>' => ['int<0, 100>', 'z.number().int().min(0).max(100)'],
        'int<0,max>' => ['int<0, max>', 'z.number().int().min(0)'],
        'int<min,100>' => ['int<min, 100>', 'z.number().int().max(100)'],
        'float' => ['float', 'z.number()'],
        'number' => ['number', 'z.number()'],
        'bool' => ['bool', 'z.boolean()'],
        'true' => ['true', 'z.literal(true)'],
        'false' => ['false', 'z.literal(false)'],
        'null (bare)' => ['null', 'z.null()'],
        'mixed' => ['mixed', 'z.unknown()'],
        'T|null' => ['string|null', 'z.string().nullable()'],
        'optional handled by caller, literal union of strings' => ["'a'|'b'", 'z.enum(["a", "b"])'],
        'literal union of ints' => ['1|2|3', 'z.union([z.literal(1), z.literal(2), z.literal(3)])'],
        'list<T>' => ['list<string>', 'z.array(z.string())'],
        'non-empty-list<T>' => ['non-empty-list<string>', 'z.array(z.string()).min(1)'],
        'T[]' => ['string[]', 'z.array(z.string())'],
        'array<int,T>' => ['array<int, string>', 'z.array(z.string())'],
        'array<string,T>' => ['array<string, int>', 'z.record(z.string(), z.number().int())'],
        'nested array{}' => ['array{a: string, b: int}', 'z.object({ a: z.string(), b: z.number().int() })'],
        'array{} with optional key' => ['array{a?: string}', 'z.object({ a: z.string().optional() })'],
    ];
});

it('converts every construct in the PHPDoc-to-zod mapping table', function (string $phpDocType, string $expectedZod): void {
    $type = parsePhpDocType($phpDocType);
    $converter = new ZodConverter(knownAliases: []);

    expect($converter->convert($type, 'root'))->toBe($expectedZod);
})->with('mappingTableCases');

it('resolves value-of<ClassName::CONST> to a string enum from the constant values', function (): void {
    $type = parsePhpDocType('value-of<' . ValueOfConstFixture::class . '::KINDS>');
    $converter = new ZodConverter(knownAliases: []);

    expect($converter->convert($type, 'root'))->toBe('z.enum(["a", "b"])');
});

it('references a known alias by its emitted const name instead of inlining it', function (): void {
    $aliasType = parsePhpDocType('array{x: string}');
    $usageType = parsePhpDocType('SomeAlias');
    $converter = new ZodConverter(knownAliases: ['SomeAlias' => $aliasType]);

    expect($converter->convert($usageType, 'root'))->toBe('someAliasProps')
        ->and($converter->usedAliases())->toBe(['SomeAlias' => true]);
});

it('throws UnsupportedShapeException naming the key path for a construct with no zod mapping', function (): void {
    $type = parsePhpDocType('array{nested: array{deep: resource}}');
    $converter = new ZodConverter(knownAliases: []);

    expect(fn () => $converter->convert($type, 'root'))
        ->toThrow(UnsupportedShapeException::class, 'unsupported PHPDoc construct at [root.nested.deep]: resource');
});
