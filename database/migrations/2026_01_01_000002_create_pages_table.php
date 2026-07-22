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
        if (Schema::hasTable('pages')) {
            return;
        }

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->foreignId('parent_id')->nullable()->constrained('pages');
            $table->string('status')->default('draft');
            $table->json('content')->nullable();
            $table->json('name')->nullable();
            $table->json('title')->nullable();
            $table->json('heading')->nullable();
            $table->json('summary')->nullable();
            $table->json('seo')->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            UserKeyColumn::define($table, 'created_by')->nullable();
            UserKeyColumn::define($table, 'updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
