<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Form\LeadStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_form_submissions', function (Blueprint $table): void {
            $table->string('lead_status')->default(LeadStatus::New->value)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('cms_form_submissions', function (Blueprint $table): void {
            $table->dropColumn('lead_status');
        });
    }
};
