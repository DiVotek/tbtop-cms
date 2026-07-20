<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Tbtop\Cms\Support\UserKeyColumn;
use Tbtop\Cms\Tests\Fixtures\UlidAdminUser;

it('declares a ulid-shaped column (char length 26, not uuid) for a HasUlids host, so the HasUlids branch is checked before HasUuids', function (): void {
    config(['tbtop-cms.admin.model' => UlidAdminUser::class]);

    $table = new Blueprint(app('db')->connection(), 'user_key_column_trait_order_test');

    $column = UserKeyColumn::define($table, 'user_id');

    expect($column->get('type'))->toBe('char')
        ->and($column->get('length'))->toBe(26);
});
