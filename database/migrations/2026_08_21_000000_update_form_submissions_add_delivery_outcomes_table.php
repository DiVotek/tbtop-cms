<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbtop_form_submissions', function (Blueprint $table): void {
            $table->timestamp('handler_handled_at')->nullable();
            $table->text('handler_error')->nullable();
            $table->timestamp('notification_handled_at')->nullable();
            $table->text('notification_error')->nullable();
        });

        DB::table('tbtop_form_submissions')
            ->where('status', 'handled')
            ->update([
                'handler_handled_at' => DB::raw('handled_at'),
                'notification_handled_at' => DB::raw('handled_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tbtop_form_submissions', function (Blueprint $table): void {
            $table->dropColumn(['handler_handled_at', 'handler_error', 'notification_handled_at', 'notification_error']);
        });
    }
};
