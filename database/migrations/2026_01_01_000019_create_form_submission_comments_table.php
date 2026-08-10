<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_form_submission_comments')) {
            return;
        }

        Schema::create('tbtop_form_submission_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('tbtop_form_submissions')->cascadeOnDelete();
            // No FK to the host users table, mirroring tbtop_user_profiles at
            // creation time (see 2026_01_01_000004) — that FK, when a host
            // wants one, is added the same way in a later migration.
            UserKeyColumn::define($table, 'user_id');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_form_submission_comments');
    }
};
