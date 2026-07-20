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
    public static function define(Blueprint $table, string $column): ColumnDefinition
    {
        if (! self::userModelResolvable()) {
            return $table->unsignedBigInteger($column);
        }

        $model = self::resolveUserModel();
        $traits = class_uses_recursive($model);

        if (isset($traits[HasUuids::class])) {
            return $table->uuid($column);
        }

        if (isset($traits[HasUlids::class])) {
            return $table->ulid($column);
        }

        if ($model->getKeyType() === 'int' && $model->getIncrementing()) {
            return $table->unsignedBigInteger($column);
        }

        throw new RuntimeException(sprintf(
            'Cannot derive a %s column type for user model %s (key type: %s, incrementing: %s). '
                . 'Supported key shapes: int autoincrement, HasUuids, HasUlids.',
            $column,
            $model::class,
            $model->getKeyType(),
            $model->getIncrementing() ? 'true' : 'false',
        ));
    }

    public static function isIntegerBacked(): bool
    {
        if (! self::userModelResolvable()) {
            return true;
        }

        $model = self::resolveUserModel();

        return $model->getKeyType() === 'int' && $model->getIncrementing();
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
