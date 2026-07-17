<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            // No FK: the host user table is configurable (tbtop-cms.admin.model)
            // and may not even be named `users` — same reasoning cms_user_profiles
            // used before its own user_id FK migration. A revision survives its
            // author's account being deleted; the author column just reads as
            // unknown (see PageRevisionWriter/the revisions tab author lookup).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
    }
};
