<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Language\LocaleCatalog;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_languages', 'og_locale')) {
            return;
        }

        Schema::table('tbtop_languages', function (Blueprint $table): void {
            // ogp.me language_TERRITORY (en_US); null = og:locale is omitted.
            $table->string('og_locale', 10)->nullable()->after('locale');
        });

        // Query builder, not the model: the observer would fire
        // LanguagesChanged per row mid-migration.
        foreach (DB::table('tbtop_languages')->pluck('locale', 'id') as $id => $locale) {
            $ogLocale = LocaleCatalog::defaultOgLocale((string) $locale);

            if ($ogLocale !== null) {
                DB::table('tbtop_languages')->where('id', $id)->update(['og_locale' => $ogLocale]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tbtop_languages', function (Blueprint $table): void {
            $table->dropColumn('og_locale');
        });
    }
};
