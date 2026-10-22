<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;

// Laravel's standard notifications table (the shape `make:notifications-table`
// generates). The admin package's header bell reads/writes it via the
// `database` channel but ships no migration for it — the demo app under
// tbtop/apps/demo owns its own copy, so cms provides one too, guarded like
// the other tables here in case a host application already created it.
//
// One departure from that shape: unless the host chose a default morph key
// type (`Schema::morphUsingUuids()` / `morphUsingUlids()`, which `morphs()`
// follows), `notifiable_id` is typed like the host's user key rather than as
// `morphs()`'s unsigned big integer. The bell notifies host users, and on a
// HasUlids/HasUuids host a strict server database refuses their keys in an
// integer column. Tables created before this are aligned by the 2026_10_22
// migration.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            if (Builder::$defaultMorphKeyType === 'int') {
                $table->string('notifiable_type');
                UserKeyColumn::define($table, 'notifiable_id');
                $table->index(['notifiable_type', 'notifiable_id']);
            } else {
                $table->morphs('notifiable');
            }
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally a no-op: up() skips creation when the host already
        // owns a notifications table, and a migration has no way to remember
        // whether it was the one that created it — dropping here could
        // destroy the host application's own notification data on a package
        // rollback. The table is shared Laravel infrastructure; a host that
        // really wants it gone drops it explicitly.
    }
};
