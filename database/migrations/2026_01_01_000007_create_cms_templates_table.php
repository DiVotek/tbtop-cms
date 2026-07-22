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
        if (Schema::hasTable('cms_templates')) {
            return;
        }

        Schema::create('cms_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('content')->nullable();
            UserKeyColumn::define($table, 'created_by')->nullable();
            UserKeyColumn::define($table, 'updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_templates');
    }
};
