<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;

/**
 * Converts one PHPDoc TypeNode into its zod fragment.
 * `$knownAliases` are emitted as a reference to the alias's own
 * exported const rather than inlined; every alias name this call actually
 * used is recorded in `$usedAliases`, in first-use order, so the emitter can
 * print alias definitions before whatever first refers to them.
 */
final class ZodConverter
{
    /** The generated file's helper that turns PHP's `[]` (an empty array, whatever its declared shape) into `{}` before parsing. */
    public const string EMPTY_ARRAY_AS_OBJECT = 'emptyArrayAsObject';

    /** @var array<string, true> alias name => used, insertion order preserved */
    private array $usedAliases = [];

    /** @param  array<string, TypeNode>  $knownAliases */
    public function __construct(private readonly array $knownAliases) {}

    public function convert(TypeNode $type, string $keyPath): string
    {
        if ($type instanceof NullableTypeNode) {
            return $this->convert($type->type, $keyPath) . '.nullable()';
        }

        if ($type instanceof UnionTypeNode) {
            return $this->convertUnion($type, $keyPath);
        }

        if ($type instanceof ArrayShapeNode) {
            return $this->convertShape($type, $keyPath);
        }

        if ($type instanceof GenericTypeNode) {
            return $this->convertGeneric($type, $keyPath);
        }

        if ($type instanceof ArrayTypeNode) {
            throw new UnsupportedShapeException($keyPath, 'T[] (keys may be non-sequential and serialize as an object; use list<T>)');
        }

        if ($type instanceof ConstTypeNode) {
            return $this->convertLiteral($type, $keyPath);
        }

        if ($type instanceof IdentifierTypeNode) {
            return $this->convertIdentifier($type, $keyPath);
        }

        throw new UnsupportedShapeException($keyPath, $type::class);
    }

    /** @return array<string, true> */
    public function usedAliases(): array
    {
        return $this->usedAliases;
    }

    /**
     * json_encode() writes an empty PHP array as `[]`, which z.object() and
     * z.record() reject — wrap any schema whose value may be empty.
     */
    public static function acceptEmptyArray(string $fragment): string
    {
        return self::EMPTY_ARRAY_AS_OBJECT . "({$fragment})";
    }

    private function convertUnion(UnionTypeNode $type, string $keyPath): string
    {
        $members = $type->types;
        $nullable = false;
        $rest = [];

        foreach ($members as $member) {
            if ($member instanceof IdentifierTypeNode && $member->name === 'null') {
                $nullable = true;

                continue;
            }

            $rest[] = $member;
        }

        if (count($rest) === 1 && $nullable) {
            return $this->convert($rest[0], $keyPath) . '.nullable()';
        }

        $literalUnion = $this->tryLiteralUnion($rest, $keyPath);
        if ($literalUnion !== null) {
            return $nullable ? $literalUnion . '.nullable()' : $literalUnion;
        }

        throw new UnsupportedShapeException($keyPath, 'union of non-literal types');
    }

    /** @param  list<TypeNode>  $members */
    private function tryLiteralUnion(array $members, string $keyPath): ?string
    {
        if ($members === []) {
            return null;
        }

        $stringLiterals = [];
        $intFragments = [];
        $allString = true;
        $allInt = true;

        foreach ($members as $member) {
            if (! $member instanceof ConstTypeNode) {
                return null;
            }

            $expr = $member->constExpr;

            if ($expr instanceof ConstExprStringNode) {
                $allInt = false;
                $stringLiterals[] = $expr->value;

                continue;
            }

            if ($expr instanceof ConstExprIntegerNode) {
                $allString = false;
                $intFragments[] = $expr->value;

                continue;
            }

            return null;
        }

        if ($allString) {
            $quoted = array_map(static fn (string $v): string => self::quote($v), $stringLiterals);

            return 'z.enum([' . implode(', ', $quoted) . '])';
        }

        if ($allInt) {
            $literals = array_map(static fn (string $v): string => "z.literal({$v})", $intFragments);

            return 'z.union([' . implode(', ', $literals) . '])';
        }

        return null;
    }

    private function convertShape(ArrayShapeNode $type, string $keyPath): string
    {
        if (self::isTuple($type)) {
            return $this->convertTuple($type, $keyPath);
        }

        return $this->objectFromFields($this->convertShapeFields($type, $keyPath), $type, $keyPath);
    }

    /**
     * `array{a: string, ...}` / `...<string, T>`: extra keys are part of the
     * contract, and a plain z.object() would strip them at parse time.
     * A shape with no required key may be an empty array at runtime, unless
     * the caller appends keys that are always present (`$alwaysHasKeys`).
     *
     * @param  list<string>  $fields  "key: fragment" entries
     */
    public function objectFromFields(array $fields, ArrayShapeNode $type, string $keyPath, bool $alwaysHasKeys = false): string
    {
        $object = 'z.object({ ' . implode(', ', $fields) . ' })';

        if (! $type->sealed) {
            $extra = $type->unsealedType === null ? 'z.unknown()' : $this->convert($type->unsealedType->valueType, "{$keyPath}.*");
            $object = "{$object}.catchall({$extra})";
        }

        return $alwaysHasKeys || self::hasRequiredKey($type) ? $object : self::acceptEmptyArray($object);
    }

    private static function hasRequiredKey(ArrayShapeNode $type): bool
    {
        return array_any($type->items, static fn (ArrayShapeItemNode $item): bool => ! $item->optional);
    }

    /** Every item positional (`array{string, int}` / `list{string, int}`): a PHP list, so a JSON array. */
    private static function isTuple(ArrayShapeNode $type): bool
    {
        if ($type->items === []) {
            return false;
        }

        foreach ($type->items as $item) {
            if ($item->keyName !== null) {
                return false;
            }
        }

        return true;
    }

    private function convertTuple(ArrayShapeNode $type, string $keyPath): string
    {
        if (! $type->sealed) {
            throw new UnsupportedShapeException($keyPath, 'an unsealed tuple');
        }

        $elements = [];

        foreach ($type->items as $index => $item) {
            if ($item->optional) {
                throw new UnsupportedShapeException("{$keyPath}.{$index}", 'an optional tuple element');
            }

            $elements[] = $this->convert($item->valueType, "{$keyPath}.{$index}");
        }

        return 'z.tuple([' . implode(', ', $elements) . '])';
    }

    /**
     * Exposed for BlockShapeExtractor, which appends the FormActionBinder
     * keys to a top-level shape's field list without string surgery on the
     * rendered object.
     *
     * @return list<string> one "key: fragment" entry per shape item
     */
    public function convertShapeFields(ArrayShapeNode $type, string $keyPath): array
    {
        $fields = [];

        foreach ($type->items as $index => $item) {
            $keyName = $item->keyName;

            if ($keyName === null) {
                throw new UnsupportedShapeException("{$keyPath}.{$index}", 'a positional item mixed with named keys');
            }

            $key = $keyName instanceof ConstExprStringNode ? $keyName->value : (string) $keyName;
            $fieldPath = $keyPath === '' ? $key : "{$keyPath}.{$key}";

            $fragment = $this->convert($item->valueType, $fieldPath);

            if ($item->optional) {
                $fragment .= '.optional()';
            }

            $fields[] = EmitterNames::objectKey($key) . ": {$fragment}";
        }

        return $fields;
    }

    private function convertGeneric(GenericTypeNode $type, string $keyPath): string
    {
        $name = $type->type->name;
        $args = $type->genericTypes;

        return match ($name) {
            'list', 'non-empty-list' => $this->convertListGeneric($name, $args, $keyPath),
            'array' => $this->convertArrayGeneric($args, $keyPath),
            'int' => $this->convertIntRange($args, $keyPath),
            'value-of' => $this->convertValueOf($args, $keyPath),
            default => throw new UnsupportedShapeException($keyPath, "generic type {$name}<>"),
        };
    }

    /** @param  list<TypeNode>  $args */
    private function convertListGeneric(string $name, array $args, string $keyPath): string
    {
        if (count($args) !== 1) {
            throw new UnsupportedShapeException($keyPath, "{$name}<> with " . count($args) . ' type arguments');
        }

        $array = 'z.array(' . $this->convert($args[0], $keyPath . '[]') . ')';

        return $name === 'non-empty-list' ? $array . '.min(1)' : $array;
    }

    /**
     * `array<int, T>` behaves as `list<T>`; `array<string, T>` as a record —
     * except `array<string, mixed>`, indistinguishable from "no shape info"
     * at any level below the top (the top level has its own untyped
     * fallback, see BlockShapeExtractor).
     *
     * @param  list<TypeNode>  $args
     */
    private function convertArrayGeneric(array $args, string $keyPath): string
    {
        if (count($args) !== 2) {
            throw new UnsupportedShapeException($keyPath, 'array<> without an explicit key type');
        }

        [$keyType, $valueType] = $args;

        if (! $keyType instanceof IdentifierTypeNode || ($keyType->name !== 'string' && $keyType->name !== 'int')) {
            throw new UnsupportedShapeException($keyPath, 'array<> with an unsupported key type');
        }

        if ($keyType->name === 'int') {
            throw new UnsupportedShapeException($keyPath, 'array<int, T> (keys may be non-sequential and serialize as an object; use list<T>)');
        }

        if ($valueType instanceof IdentifierTypeNode && $valueType->name === 'mixed') {
            throw new UnsupportedShapeException($keyPath, 'array<string, mixed> has no usable shape');
        }

        return self::acceptEmptyArray('z.record(z.string(), ' . $this->convert($valueType, $keyPath . '.*') . ')');
    }

    /** @param  list<TypeNode>  $args */
    private function convertIntRange(array $args, string $keyPath): string
    {
        if (count($args) !== 2) {
            throw new UnsupportedShapeException($keyPath, 'int<> with ' . count($args) . ' type arguments');
        }

        $number = 'z.number().int()';

        $min = $this->intBoundLiteral($args[0]);
        if ($min !== null) {
            $number .= ".min({$min})";
        }

        $max = $this->intBoundLiteral($args[1]);
        if ($max !== null) {
            $number .= ".max({$max})";
        }

        return $number;
    }

    private function intBoundLiteral(TypeNode $bound): ?string
    {
        if ($bound instanceof IdentifierTypeNode && ($bound->name === 'min' || $bound->name === 'max')) {
            return null;
        }

        if ($bound instanceof ConstTypeNode && $bound->constExpr instanceof ConstExprIntegerNode) {
            return $bound->constExpr->value;
        }

        return null;
    }

    /** @param  list<TypeNode>  $args */
    private function convertValueOf(array $args, string $keyPath): string
    {
        if (count($args) !== 1 || ! $args[0] instanceof ConstTypeNode || ! $args[0]->constExpr instanceof ConstFetchNode) {
            throw new UnsupportedShapeException($keyPath, 'value-of<> without a self::CONST argument');
        }

        $fetch = $args[0]->constExpr;
        $className = $fetch->className === 'self' || $fetch->className === 'static'
            ? throw new UnsupportedShapeException($keyPath, 'value-of<self::CONST> outside a class context is not resolvable here')
            : $fetch->className;

        return $this->enumFromClassConstant($className, $fetch->name, $keyPath);
    }

    private function enumFromClassConstant(string $className, string $constantName, string $keyPath): string
    {
        if (! class_exists($className)) {
            throw new UnsupportedShapeException($keyPath, "value-of<{$className}::{$constantName}> names an unknown class");
        }

        $reflection = new ReflectionClass($className);

        if (! $reflection->hasConstant($constantName)) {
            throw new UnsupportedShapeException($keyPath, "value-of<{$className}::{$constantName}> names an unknown constant");
        }

        $values = $reflection->getConstant($constantName);

        if (! is_array($values) || $values === []) {
            throw new UnsupportedShapeException($keyPath, "value-of<{$className}::{$constantName}> is not a non-empty array constant");
        }

        return $this->enumFromValues(array_values($values), $keyPath);
    }

    /** @param  list<mixed>  $values */
    private function enumFromValues(array $values, string $keyPath): string
    {
        $allString = array_all($values, static fn (mixed $v): bool => is_string($v));
        $allInt = array_all($values, static fn (mixed $v): bool => is_int($v));

        if ($allString) {
            $quoted = array_map(static fn (string $v): string => self::quote($v), $values);

            return 'z.enum([' . implode(', ', $quoted) . '])';
        }

        if ($allInt) {
            $literals = array_map(static fn (int $v): string => "z.literal({$v})", $values);

            return 'z.union([' . implode(', ', $literals) . '])';
        }

        throw new UnsupportedShapeException($keyPath, 'value-of<> constant with mixed value types');
    }

    private function convertLiteral(ConstTypeNode $type, string $keyPath): string
    {
        $expr = $type->constExpr;

        if ($expr instanceof ConstExprStringNode) {
            return 'z.literal(' . self::quote($expr->value) . ')';
        }

        if ($expr instanceof ConstExprIntegerNode) {
            return "z.literal({$expr->value})";
        }

        if ($expr instanceof ConstExprTrueNode) {
            return 'z.literal(true)';
        }

        if ($expr instanceof ConstExprFalseNode) {
            return 'z.literal(false)';
        }

        throw new UnsupportedShapeException($keyPath, 'unsupported literal constant expression');
    }

    private function convertIdentifier(IdentifierTypeNode $type, string $keyPath): string
    {
        $name = $type->name;

        if (isset($this->knownAliases[$name])) {
            $this->usedAliases[$name] = true;

            return EmitterNames::aliasConst($name);
        }

        return match ($name) {
            'string' => 'z.string()',
            'non-empty-string' => 'z.string().min(1)',
            'int', 'positive-int' => $name === 'positive-int' ? 'z.number().int().positive()' : 'z.number().int()',
            'float', 'number' => 'z.number()',
            'bool', 'boolean', 'true', 'false' => $this->boolFragment($name),
            'null' => 'z.null()',
            'mixed' => 'z.unknown()',
            default => throw new UnsupportedShapeException($keyPath, $name),
        };
    }

    private function boolFragment(string $name): string
    {
        return match ($name) {
            'true' => 'z.literal(true)',
            'false' => 'z.literal(false)',
            default => 'z.boolean()',
        };
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
