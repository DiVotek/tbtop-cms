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
        if (Schema::hasTable('tbtop_redirects')) {
            return;
        }

        Schema::create('tbtop_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 5)->nullable();
            $table->string('from_path', 600);
            $table->string('to_path', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_auto')->default(false);
            UserKeyColumn::define($table, 'created_by')->nullable();
            UserKeyColumn::define($table, 'updated_by')->nullable();

            $table->unique(['locale', 'from_path']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_redirects');
    }
};
