<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_not_found_hits')) {
            return;
        }

        Schema::create('tbtop_not_found_hits', function (Blueprint $table): void {
            $table->id();
            // Empty string, never null, when the locale can't be determined —
            // a nullable column in the (locale, path_hash) unique index would
            // not dedupe (NULL <> NULL in a unique constraint), fragmenting
            // the hit counter across rows for the same path.
            $table->string('locale', 32);
            $table->text('path');
            // sha256 of `path`, not `path` itself: MySQL's utf8mb4 key limit
            // (3072 bytes) can't safely index an unbounded text column.
            $table->char('path_hash', 64);
            $table->unsignedBigInteger('hits')->default(1);
            $table->string('last_referer', 2048)->nullable();
            $table->string('last_user_agent', 512)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_ignored')->default(false);
            $table->timestamps();

            $table->unique(['locale', 'path_hash']);
            $table->index('last_seen_at');
            $table->index('is_ignored');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_not_found_hits');
    }
};
