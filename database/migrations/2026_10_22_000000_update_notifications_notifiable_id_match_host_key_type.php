<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;

// The create migration used to build `notifications.notifiable_id` with
// `morphs()`, an unsigned big integer whatever the host's user key. The bell
// writes the host user's key there, so on a HasUlids/HasUuids host a strict
// server database refused every bell notification ("Data truncated for column
// 'notifiable_id'"). This changes such a column to the host key type, as 000014
// does for the package's own user-id columns.
//
// The table may be the host's own (the create migration skips then). The bell
// writes host user keys into it all the same, so it gets the same change.
//
// Unlike 000014 this only widens an integer column, never narrows a string one
// on an int-key host: a string column already holds integer keys, and a
// host-owned table may hold string keys of other notifiable models.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasColumn('notifications', 'notifiable_id')) {
            return;
        }

        if (UserKeyColumn::isIntegerBacked() || ! $this->isIntegerType(Schema::getColumnType('notifications', 'notifiable_id'))) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            UserKeyColumn::define($table, 'notifiable_id')->change();
        });
    }

    public function down(): void
    {
        // Not reversed: narrowing the column back to an integer would truncate
        // the user keys written into it since.
    }

    private function isIntegerType(string $type): bool
    {
        return str_contains(strtolower($type), 'int');
    }
};
