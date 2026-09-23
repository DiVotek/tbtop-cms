<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;

/**
 * Rewrites the class in every `Class::CONST` reference (as in
 * `value-of<Class::CONST>`) to a FQCN, so ZodConverter's reflection lookup
 * never sees a docblock-relative name: `self`/`static` become `$selfClass`,
 * any other name is resolved like PHP would in `$scope`'s file.
 */
final class ConstFetchResolver
{
    /**
     * Same rewrite as resolve(), narrowed so an ArrayShapeNode caller keeps an ArrayShapeNode without a runtime type check.
     *
     * @param  ReflectionClass<object>  $scope  the class or trait whose docblock declares the type
     */
    public static function resolveShape(ArrayShapeNode $type, string $selfClass, ReflectionClass $scope): ArrayShapeNode
    {
        $items = array_map(
            static fn (ArrayShapeItemNode $item): ArrayShapeItemNode => new ArrayShapeItemNode(
                $item->keyName,
                $item->optional,
                self::resolve($item->valueType, $selfClass, $scope),
            ),
            $type->items,
        );

        if ($type->sealed) {
            return ArrayShapeNode::createSealed($items, $type->kind);
        }

        $unsealed = $type->unsealedType === null ? null : new ArrayShapeUnsealedTypeNode(
            self::resolve($type->unsealedType->valueType, $selfClass, $scope),
            $type->unsealedType->keyType,
        );

        return ArrayShapeNode::createUnsealed($items, $unsealed, $type->kind);
    }

    /** @param  ReflectionClass<object>  $scope  the class or trait whose docblock declares the type */
    public static function resolve(TypeNode $type, string $selfClass, ReflectionClass $scope): TypeNode
    {
        if ($type instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($type->type, $selfClass, $scope));
        }

        if ($type instanceof UnionTypeNode) {
            return new UnionTypeNode(
                array_map(static fn (TypeNode $t): TypeNode => self::resolve($t, $selfClass, $scope), $type->types),
            );
        }

        if ($type instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($type->type, $selfClass, $scope));
        }

        if ($type instanceof ArrayShapeNode) {
            return self::resolveShape($type, $selfClass, $scope);
        }

        if ($type instanceof GenericTypeNode) {
            return new GenericTypeNode(
                $type->type,
                array_map(static fn (TypeNode $t): TypeNode => self::resolve($t, $selfClass, $scope), $type->genericTypes),
                $type->variances,
            );
        }

        if ($type instanceof ConstTypeNode && $type->constExpr instanceof ConstFetchNode && $type->constExpr->className !== '') {
            $fetch = $type->constExpr;
            $className = $fetch->className === 'self' || $fetch->className === 'static'
                ? $selfClass
                : DocblockClassResolver::resolve($fetch->className, $scope);

            return new ConstTypeNode(new ConstFetchNode($className, $fetch->name));
        }

        return $type;
    }
}
