<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\Concerns\ReadsFiles;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Purely file-based heuristics over host source, not a real bundler
 * resolution — there is no reliable, cheap way to ask Vite "did this import
 * actually resolve" from PHP. Three independent host files are inspected
 * (JS entry, admin page component, CSS entry); each is a heuristic in its
 * own right and a missing file or unmatched pattern degrades that piece to a
 * warning rather than an error, since a host may legitimately structure
 * these differently (a monorepo, a renamed entry) without being broken.
 * Never throws and never escalates past warning — see the class docblock
 * on HealthCheck for why file-heuristic checks in particular must stay soft.
 */
final class JsWiringCheck implements HealthCheck
{
    use ReadsFiles;

    private const string BLOCKS_IMPORT = '@cms/admin/blocks';

    private const string ADMIN_PAGE_IMPORT = '@tbtop/inertia-admin';

    private const string ADMIN_PAGE_RESOLVE = 'admin/page';

    private const string TAILWIND_SOURCE_NEEDLE = 'vendor/tbtop/cms';

    /**
     * The admin entry comes first: `admin:install` publishes a dedicated
     * resources/js/admin.tsx so the panel bundle stays out of the public
     * frontend, and that is where the admin wiring belongs. The app entry
     * stays as a fallback for hosts wired before that entry existed, which
     * put the same imports in their single entry.
     *
     * @var list<string>
     */
    private const array JS_ENTRY_CANDIDATES = [
        'resources/js/admin.tsx',
        'resources/js/admin.jsx',
        'resources/js/admin.ts',
        'resources/js/app.tsx',
        'resources/js/app.jsx',
        'resources/js/app.ts',
    ];

    /** @var list<string> */
    private const array ADMIN_PAGE_CANDIDATES = [
        'resources/js/pages/admin/page.tsx',
        'resources/js/pages/admin/page.jsx',
    ];

    /** @var list<string> */
    private const array CSS_CANDIDATES = ['resources/css/admin.css', 'resources/css/app.css'];

    public function id(): string
    {
        return 'js_wiring';
    }

    public function run(): HealthCheckResult
    {
        $problems = [
            ...$this->checkBlocksImport(),
            ...$this->checkAdminPageComponent(),
            ...$this->checkTailwindSource(),
        ];

        if ($problems === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.js_wiring.label',
                messageKey: 'tbtop-cms::health.checks.js_wiring.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.js_wiring.label',
            messageKey: 'tbtop-cms::health.checks.js_wiring.warning',
            hintKey: 'tbtop-cms::health.checks.js_wiring.hints.combined',
            hintFragments: $problems,
        );
    }

    /** @return list<array{key: string, replace: array<string, string>}> Hint fragments, one per unresolved heuristic. */
    private function checkBlocksImport(): array
    {
        $path = $this->firstExisting(self::JS_ENTRY_CANDIDATES);

        if ($path === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.entry_not_found', 'replace' => ['candidates' => implode(', ', self::JS_ENTRY_CANDIDATES)]]];
        }

        $source = $this->readFile($path);

        if ($source === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.unreadable', 'replace' => ['path' => $path]]];
        }

        // Matched without the surrounding quotes: hosts format JS to their own
        // conventions, and a double-quoted import is the same wiring.
        if (! str_contains($source, self::BLOCKS_IMPORT)) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.blocks_import_missing', 'replace' => ['path' => $path]]];
        }

        return [];
    }

    /** @return list<array{key: string, replace: array<string, string>}> */
    private function checkAdminPageComponent(): array
    {
        // The canonical wiring resolves 'admin/page' inline in the JS entry
        // (`if (name === 'admin/page') return AdminPage`) — a separate page
        // component file is the alternative layout, not the primary one, so
        // the entry is consulted first or every correct install warns.
        $entry = $this->firstExisting(self::JS_ENTRY_CANDIDATES);

        if ($entry !== null) {
            $source = $this->readFile($entry);

            // An unreadable entry is reported by checkBlocksImport already;
            // here it just means the inline resolver cannot be confirmed.
            if ($source !== null && str_contains($source, self::ADMIN_PAGE_RESOLVE) && str_contains($source, self::ADMIN_PAGE_IMPORT)) {
                return [];
            }
        }

        $path = $this->firstExisting(self::ADMIN_PAGE_CANDIDATES);

        if ($path === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.admin_page_not_found', 'replace' => ['candidates' => implode(', ', self::ADMIN_PAGE_CANDIDATES)]]];
        }

        $source = $this->readFile($path);

        if ($source === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.unreadable', 'replace' => ['path' => $path]]];
        }

        if (! str_contains($source, self::ADMIN_PAGE_IMPORT)) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.admin_page_import_missing', 'replace' => ['path' => $path]]];
        }

        return [];
    }

    /** @return list<array{key: string, replace: array<string, string>}> */
    private function checkTailwindSource(): array
    {
        $path = $this->firstExisting(self::CSS_CANDIDATES);

        if ($path === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.css_not_found', 'replace' => ['candidates' => implode(', ', self::CSS_CANDIDATES)]]];
        }

        $source = $this->readFile($path);

        if ($source === null) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.unreadable', 'replace' => ['path' => $path]]];
        }

        if (! str_contains($source, self::TAILWIND_SOURCE_NEEDLE)) {
            return [['key' => 'tbtop-cms::health.checks.js_wiring.hints.source_missing', 'replace' => ['path' => $path]]];
        }

        return [];
    }

    /** @param  list<string>  $relativeCandidates */
    private function firstExisting(array $relativeCandidates): ?string
    {
        foreach ($relativeCandidates as $relative) {
            $path = base_path($relative);
            // isFile, not exists: a directory squatting on a candidate path
            // is "not found", not a source file to read.
            if (File::isFile($path)) {
                return $path;
            }
        }

        return null;
    }
}
