<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The page editor drops its General-tab `title` field: the meta title is
 * edited only as `seo.title` on the SEO tab. For each locale, a non-blank
 * `title` is copied into `seo.title` when that locale's `seo.title` is empty,
 * so the <title> cascade (`seo.title` → `title` → `name`, PageOwnSeo::title())
 * renders exactly what it did before and the editor finds the value on the
 * SEO tab. A filled `seo.title` is never overwritten, and a legacy scalar
 * `seo.title` counts as filled for every locale, as LocalizedSeo reads it.
 * The `title` column itself is left as it is.
 *
 * Raw rows, not the Page model: a historical migration must not change with
 * later model scopes, casts or hooks — and the copy changes no rendered
 * output, so there is no revision or cache bust to trigger. Each chunk is
 * read in full with get() before any row is written: an open cursor on MySQL
 * does not survive a write on the same connection. JSON is decoded in PHP,
 * so it runs the same on SQLite and MySQL. Soft-deleted rows are included.
 * A rerun finds every copied locale filled and does nothing.
 */
return new class extends Migration
{
    private const int CHUNK = 200;

    public function up(): void
    {
        $lastId = 0;

        do {
            $rows = DB::table('tbtop_pages')->select(['id', 'title', 'seo'])
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::CHUNK)->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $seo = self::seoWithTitle(self::decode($row->seo), self::decode($row->title));

                if ($seo !== null) {
                    DB::table('tbtop_pages')->where('id', $lastId)->update(['seo' => json_encode($seo, JSON_THROW_ON_ERROR)]);
                }
            }
        } while ($rows->count() === self::CHUNK);
    }

    /**
     * No-op: a copied value cannot be told apart from a meta title an editor
     * typed (often the same words), and editors may have changed it since.
     * Leaving it is harmless — while `title` stays in the cascade, a copy
     * equal to it renders the same <title> as no copy at all.
     */
    public function down(): void {}

    /**
     * The `seo` blob with each missing locale's title copied in, or null when
     * nothing changes. Only a locale map of `title` is read: anything else is
     * not a translation the cascade reads either.
     *
     * @param  array<array-key, mixed>|string|null  $seo
     * @param  array<array-key, mixed>|string|null  $title
     * @return array<array-key, mixed>|null
     */
    private static function seoWithTitle(array | string | null $seo, array | string | null $title): ?array
    {
        if (! is_array($title)) {
            return null;
        }

        $seo = is_array($seo) ? $seo : [];
        $current = $seo['title'] ?? null;
        if (is_string($current) && $current !== '') {
            return null;
        }

        $metaTitles = is_array($current) ? $current : [];
        $isChanged = false;
        foreach ($title as $locale => $value) {
            $existing = $metaTitles[$locale] ?? null;
            if (! is_string($value) || trim($value) === '' || (is_string($existing) && $existing !== '')) {
                continue;
            }

            $metaTitles[$locale] = $value;
            $isChanged = true;
        }

        return $isChanged ? [...$seo, 'title' => $metaTitles] : null;
    }

    /** @return array<array-key, mixed>|string|null */
    private static function decode(mixed $json): array | string | null
    {
        if (! is_string($json) || $json === '') {
            return null;
        }

        $value = json_decode($json, true);

        return is_array($value) || is_string($value) ? $value : null;
    }
};
