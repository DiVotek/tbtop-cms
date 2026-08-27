<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_notification_log')) {
            return;
        }

        Schema::create('tbtop_notification_log', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('channel');
            $table->string('audience');
            // Whichever address the channel delivered to: email, phone, or a
            // notifiable's identifying string — free text, not a foreign key
            // (a bell recipient's notifiable may live on any host user model).
            $table->string('recipient');
            $table->string('locale');
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_notification_log');
    }
};
