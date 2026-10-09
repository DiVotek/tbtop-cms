<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;
use Tbtop\Cms\Tests\Fixtures\UlidAdminUser;

// DDL on the default connection: on MySQL it must land in the worker database, not the shared base one.
uses(RefreshDatabase::class);

it('declares a ulid-shaped column (char length 26, not uuid) for a HasUlids host, so the HasUlids branch is checked before HasUuids', function (): void {
    config(['tbtop-cms.admin.model' => UlidAdminUser::class]);

    $column = null;

    Schema::create('user_key_column_trait_order_test', function (Blueprint $table) use (&$column): void {
        $column = UserKeyColumn::define($table, 'user_id');
    });

    Schema::dropIfExists('user_key_column_trait_order_test');

    expect($column)->toBeInstanceOf(ColumnDefinition::class)
        ->and($column->get('type'))->toBe('char')
        ->and($column->get('length'))->toBe(26);
});
