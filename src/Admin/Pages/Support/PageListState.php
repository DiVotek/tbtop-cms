<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Enums\SeoState;
use Tbtop\Cms\Page\Models\Page;

/**
 * The pages list's two computed columns, `display_state` and `seo_state`,
 * plus the hover line explaining the SEO badge. The SQL and the tooltip read
 * the same three facts and must stay in step, so they live together.
 */
final class PageListState
{
    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public static function select(Builder $query, string $table): Builder
    {
        return $query
            ->selectRaw(self::displayStateSql($table), self::displayStateBindings())
            ->selectRaw(self::seoStateSql($table));
    }

    /**
     * SQL mirror of Page::displayState() (that method stays the source of
     * truth for the model-level API — see its docblock) so the status column
     * can badge scheduled/expired without an N+1 model hydration per row.
     * Plain ANSI CASE/COALESCE, no driver-specific function, same portability
     * approach as AuthorNames::subselect() — reads identically on sqlite
     * (tests), mysql, and postgres (hosts).
     *
     * Columns are table-qualified: PageSortKey::apply() self-joins
     * tbtop_pages up to 4 levels deep as sort_key_p1..p4, so an unqualified
     * `status`/`published_at`/`unpublish_at` is ambiguous once those joins
     * are in the same query.
     */
    private static function displayStateSql(string $table): string
    {
        return <<<SQL
            CASE
                WHEN {$table}.status != ? THEN ?
                WHEN {$table}.published_at IS NOT NULL AND {$table}.published_at > ? THEN ?
                WHEN {$table}.unpublish_at IS NOT NULL AND {$table}.unpublish_at <= ? THEN ?
                ELSE ?
            END as display_state
            SQL;
    }

    /** @return list<string> */
    private static function displayStateBindings(): array
    {
        $now = now()->toDateTimeString();

        return [
            PageStatus::Published->value, PageDisplayState::Draft->value,
            $now, PageDisplayState::Scheduled->value,
            $now, PageDisplayState::Expired->value,
            PageDisplayState::Published->value,
        ];
    }

    /**
     * `name`/`title`/`summary`/`seo` are all translatable JSON-map columns
     * (`{"en": "...", "uk": "..."}`, see Page::$translatable and
     * LocalizedSeo's docblock) — checking whether a given LOCALE's value
     * inside that JSON is empty needs per-driver JSON extraction:
     * `JSON_EXTRACT`/`->>'$.en'` on MySQL, `->>'en'` on Postgres, a third
     * shape on sqlite. Three divergent syntaxes for one column is exactly
     * the "ugly or unreliable" case this column is meant to avoid (see the
     * class docblock precedent: AuthorNames::subselect() and
     * displayStateSql() above both stay on plain ANSI SQL for the same
     * reason), so this narrows to what a plain CASE can express reliably
     * everywhere: `is_indexable` (a real boolean column) plus whether
     * `name`/`title`/`summary` hold any content in ANY locale (see
     * emptyTranslatableSql()), not whether the resolved value for one
     * particular locale is empty. A page with content
     * only in a non-default locale reads as "has a title source" here even
     * if the default locale's slice is blank — a false pass, not a false
     * fail, which is the safer direction for a list-page hint whose
     * authoritative detail lives in the per-page SEO checklist
     * (SeoChecklist::for()).
     *
     * Selected as SeoState's numeric rank, not its name, so the column sorts
     * worst-first (see the enum). The ranks are interpolated from the enum
     * rather than bound: they are its own integer cases, never user input, and
     * a CASE arm cannot take a placeholder on every driver alike.
     */
    private static function seoStateSql(string $table): string
    {
        $emptyTitle = self::emptyTranslatableSql($table, 'title');
        $emptyName = self::emptyTranslatableSql($table, 'name');
        $emptySummary = self::emptyTranslatableSql($table, 'summary');

        $fail = SeoState::Fail->value;
        $warn = SeoState::Warn->value;
        $pass = SeoState::Pass->value;

        return <<<SQL
            CASE
                WHEN {$emptyName} AND {$emptyTitle} THEN {$fail}
                WHEN {$emptySummary} OR NOT {$table}.is_indexable THEN {$warn}
                ELSE {$pass}
            END as seo_state
            SQL;
    }

    /**
     * True when a translatable column holds no content in any locale.
     *
     * An unset translatable column is not NULL — the cast writes `[]` for an
     * empty map, so emptiness cannot be tested with IS NULL. Clearing a field
     * that HAD content is the harsher case: the locale key survives with a
     * blank value, so the column stores `{"en":""}`, which a comparison
     * against `[]`/`{}` reads as populated and badges Good.
     *
     * Distinguishing "no keys" from "keys with blank values" means asking the
     * JSON itself, and the three drivers spell that differently — hence the
     * per-driver branch, following PageSortKey::padId()'s precedent for the
     * cases where portable SQL genuinely cannot express the question. Each
     * branch concatenates every value in the map and asks whether the result
     * is empty, so one non-blank locale is enough to count as content.
     *
     * Whitespace-only values still read as content; that imprecision is a
     * false pass, the safe direction for a list hint whose authoritative view
     * is the per-page checklist (SeoChecklist::for()).
     */
    private static function emptyTranslatableSql(string $table, string $column): string
    {
        $col = "{$table}.{$column}";

        return match (DB::connection()->getDriverName()) {
            // json_each tolerates arrays and scalars, so `[]` and `""` need no
            // type guard; `type = 'text'` keeps the test to string values.
            'sqlite' => "NOT EXISTS (SELECT 1 FROM json_each({$col}) WHERE type = 'text' AND value <> '')",
            // JSON_SEARCH matches string scalars only and returns NULL when
            // nothing matches. The pattern is '_%', not '%': `%` matches zero
            // characters, so it would match `""` and read blank as content.
            // The JSON_TYPE guard keeps `[]`/`""` off the search, and short-
            // circuits before JSON_SEARCH sees a non-object.
            'mysql', 'mariadb' => "({$col} IS NULL OR JSON_TYPE({$col}) <> 'OBJECT' OR JSON_SEARCH({$col}, 'one', '_%') IS NULL)",
            // jsonb_each_text() raises a hard ERROR on `[]` — CASE branch
            // order does not reliably protect a subquery from evaluation, so
            // this uses jsonpath instead: lax mode (the default) converts the
            // structural error on a non-object into an empty sequence.
            // COALESCE is load-bearing — on a SQL NULL the function returns
            // NULL, and `NOT NULL` is NULL rather than true.
            'pgsql' => "NOT jsonb_path_exists(COALESCE({$col}, 'null')::jsonb, 'lax \$.* ? (@.type() == \"string\" && @ <> \"\")')",
            // Unknown driver: the literal test. It misses blank locale entries,
            // which is a false pass — never a false fail.
            default => "COALESCE({$col}, '[]') IN ('[]', '{}', '\"\"')",
        };
    }

    /**
     * What the badge is actually complaining about, as a hover line.
     *
     * Derived from the row the table already loaded — no SeoChecklist::for()
     * call, which costs several queries per page and would turn a 50-row list
     * into hundreds of them. That means this reads the same three facts
     * seoStateSql() does and phrases them; the two must stay in step, so the
     * conditions below mirror that CASE arm for arm rather than asking a
     * richer question the badge cannot answer.
     *
     * A page with nothing wrong gets no tooltip: attachTooltips() drops empty
     * strings, so the badge simply has none.
     */
    public static function seoTooltip(Page $row): string
    {
        $reasons = [];

        // seo.title is checked here but NOT in seoStateSql(): reaching a
        // nested JSON key needs a different dialect per driver, two of which
        // CI cannot exercise (see that method's docblock). So a page carrying
        // only a meta title still badges red — but it must not be TOLD it has
        // no title when it has one. Skipping the line leaves the badge
        // unexplained, which is the honest reading: the badge is being
        // conservative, and the page's own SEO tab has the real verdict.
        if (self::allTranslationsBlank($row, 'name')
            && self::allTranslationsBlank($row, 'title')
            && ! self::hasSeoTitle($row)
        ) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.title');
        }

        if (self::allTranslationsBlank($row, 'summary')) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.summary');
        }

        if (! $row->is_indexable) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.not_indexable');
        }

        return implode("\n", $reasons);
    }

    /**
     * True when the page carries a hand-written meta title in any locale.
     * The `seo` bag is already on the loaded row, so this reads it directly
     * — see the note in seoTooltip() for why the badge's SQL cannot.
     */
    private static function hasSeoTitle(Page $row): bool
    {
        $seo = is_array($row->seo) ? $row->seo : [];
        $title = $seo['title'] ?? null;

        if (is_string($title)) {
            return $title !== '';
        }

        foreach ((array) $title as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The PHP counterpart of emptyTranslatableSql(): true when a translatable
     * column holds no non-empty value in any locale. Reads the already-loaded
     * attribute, so it costs nothing per row.
     *
     * Compared against '' and NOT trimmed, deliberately: the SQL counts a
     * whitespace-only value as content (`value <> ''`, documented there as a
     * false pass in the safe direction). Trimming here would be the stricter
     * rule, which sounds better in isolation but makes the tooltip contradict
     * the badge it explains — a page with a single-space summary would badge
     * Good and be told "No summary".
     */
    private static function allTranslationsBlank(Page $row, string $attribute): bool
    {
        foreach ($row->getTranslations($attribute) as $value) {
            if ((string) $value !== '') {
                return false;
            }
        }

        return true;
    }
}
