<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payload's field order (OrderedPayload). Existing rows stay null:
     * MySQL has already normalized their keys, so their order is not
     * recoverable, and they read in stored order as before.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_form_submissions', 'payload_order')) {
            return;
        }

        Schema::table('tbtop_form_submissions', function (Blueprint $table): void {
            $table->json('payload_order')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbtop_form_submissions', 'payload_order')) {
            return;
        }

        Schema::table('tbtop_form_submissions', function (Blueprint $table): void {
            $table->dropColumn('payload_order');
        });
    }
};
