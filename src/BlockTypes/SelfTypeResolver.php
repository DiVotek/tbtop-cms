<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/** Rewrites `self`/`static` in a `value-of<self::CONST>` reference to a concrete class name, so ZodConverter's reflection lookup never sees them. */
final class SelfTypeResolver
{
    /** Same rewrite as resolve(), narrowed so an ArrayShapeNode caller keeps an ArrayShapeNode without a runtime type check. */
    public static function resolveShape(ArrayShapeNode $type, string $selfClass): ArrayShapeNode
    {
        $items = array_map(
            static fn (ArrayShapeItemNode $item): ArrayShapeItemNode => new ArrayShapeItemNode(
                $item->keyName,
                $item->optional,
                self::resolve($item->valueType, $selfClass),
            ),
            $type->items,
        );

        return $type->sealed
            ? ArrayShapeNode::createSealed($items, $type->kind)
            : ArrayShapeNode::createUnsealed($items, $type->unsealedType, $type->kind);
    }

    public static function resolve(TypeNode $type, string $selfClass): TypeNode
    {
        if ($type instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($type->type, $selfClass));
        }

        if ($type instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                static fn (TypeNode $t): TypeNode => self::resolve($t, $selfClass),
                $type->types,
            ));
        }

        if ($type instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($type->type, $selfClass));
        }

        if ($type instanceof ArrayShapeNode) {
            return self::resolveShape($type, $selfClass);
        }

        if ($type instanceof GenericTypeNode) {
            return new GenericTypeNode(
                $type->type,
                array_map(static fn (TypeNode $t): TypeNode => self::resolve($t, $selfClass), $type->genericTypes),
                $type->variances,
            );
        }

        if ($type instanceof ConstTypeNode && $type->constExpr instanceof ConstFetchNode) {
            $fetch = $type->constExpr;

            if ($fetch->className === 'self' || $fetch->className === 'static') {
                return new ConstTypeNode(new ConstFetchNode($selfClass, $fetch->name));
            }
        }

        return $type;
    }
}
