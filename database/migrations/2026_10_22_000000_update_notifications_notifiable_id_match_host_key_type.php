<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;

// The create migration used to build `notifications.notifiable_id` with
// `morphs()`, an unsigned big integer whatever the host's user key. The bell
// writes the host user's key there, so on a HasUlids/HasUuids host a strict
// server database refused every bell notification ("Data truncated for column
// 'notifiable_id'"). This changes such a column to the host key type, as 000014
// does for the package's own user-id columns, keeping its nullability.
//
// The table may be the host's own (the create migration skips then). The bell
// writes host user keys into it all the same, so it gets the same change.
//
// Left alone:
// - a string column on an int-key host (unlike 000014, this never narrows): a
//   string column already holds integer keys, and a host-owned table may hold
//   string keys of other notifiable models;
// - any column when the host chose a default morph key type
//   (`Schema::morphUsingUuids()` / `morphUsingUlids()`): `morphs()` already
//   followed that choice, and an integer column that predates it is the
//   host's to migrate along with its other morph columns.
return new class extends Migration
{
    public function up(): void
    {
        if (Builder::$defaultMorphKeyType !== 'int' || UserKeyColumn::isIntegerBacked() || ! Schema::hasTable('notifications')) {
            return;
        }

        $column = collect(Schema::getColumns('notifications'))->firstWhere('name', 'notifiable_id');

        if (! is_array($column) || ! str_contains(strtolower((string) $column['type_name']), 'int')) {
            return;
        }

        $nullable = (bool) $column['nullable'];

        Schema::table('notifications', function (Blueprint $table) use ($nullable): void {
            // change() redefines the whole column: an unstated nullable() would make it NOT NULL.
            UserKeyColumn::define($table, 'notifiable_id')->nullable($nullable)->change();
        });
    }

    public function down(): void
    {
        // Not reversed: narrowing the column back to an integer would truncate
        // the user keys written into it since.
    }
};
