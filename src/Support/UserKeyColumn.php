<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use RuntimeException;

use function class_uses_recursive;
use function config;

/**
 * Declares a user-id-typed column (user_id, created_by, updated_by) matching
 * the configured host user model's primary key shape, so both int-PK and
 * UUID/ULID-PK hosts migrate and write successfully. See issue #76.
 */
final class UserKeyColumn
{
    private const INTEGER = 'integer';

    private const UUID = 'uuid';

    private const ULID = 'ulid';

    public static function define(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::shape()) {
            self::INTEGER => $table->unsignedBigInteger($column),
            self::UUID => $table->uuid($column),
            self::ULID => $table->ulid($column),
            default => throw new RuntimeException('Unsupported user key column shape.'),
        };
    }

    public static function isIntegerBacked(): bool
    {
        return self::shape() === self::INTEGER;
    }

    public static function matchesSchemaType(string $type, string $fullType): bool
    {
        $type = strtolower($type);
        $fullType = strtolower($fullType);

        return match (self::shape()) {
            self::INTEGER => str_contains($type, 'int'),
            self::UUID => $type === 'uuid'
                || (preg_match('/^(?:char|varchar)\s*\(36\)$/', $fullType) === 1)
                || $fullType === 'varchar',
            self::ULID => preg_match('/^char\s*\(26\)$/', $fullType) === 1,
            default => throw new RuntimeException('Unsupported user key column shape.'),
        };
    }

    private static function shape(): string
    {
        if (! self::userModelResolvable()) {
            return self::INTEGER;
        }

        $model = self::resolveUserModel();
        $traits = class_uses_recursive($model);

        if (isset($traits[HasUlids::class])) {
            return self::ULID;
        }

        if (isset($traits[HasUuids::class])) {
            return self::UUID;
        }

        if ($model->getKeyType() === 'int' && $model->getIncrementing()) {
            return self::INTEGER;
        }

        throw new RuntimeException(sprintf(
            'Cannot derive a user key column type for user model %s (key type: %s, incrementing: %s). '
                . 'Supported key shapes: int autoincrement, HasUuids, HasUlids.',
            $model::class,
            $model->getKeyType(),
            $model->getIncrementing() ? 'true' : 'false',
        ));
    }

    private static function userModelResolvable(): bool
    {
        return class_exists(self::userModelClass());
    }

    private static function resolveUserModel(): Model
    {
        $class = self::userModelClass();

        return new $class;
    }

    /** @return class-string<Model> */
    private static function userModelClass(): string
    {
        /** @var class-string<Model> $class */
        $class = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $class;
    }
}
