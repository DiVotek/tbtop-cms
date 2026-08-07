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
        if (Schema::hasTable('tbtop_user_profiles')) {
            return;
        }

        Schema::create('tbtop_user_profiles', function (Blueprint $table): void {
            $table->id();
            UserKeyColumn::define($table, 'user_id')->unique();
            $table->string('locale')->nullable();
            $table->string('avatar_media_id')->nullable();
            $table->string('display_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_user_profiles');
    }
};
