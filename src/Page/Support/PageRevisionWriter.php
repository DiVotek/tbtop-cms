<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use DomainException;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;

/**
 * Writes and restores content-revision snapshots for a Page. Registered as
 * the PageRevisions observer's collaborator (app()-resolved, mirroring
 * PageSlugWriter's registration) rather than a static utility, so tests can
 * swap it — though nothing currently does.
 *
 * The feature is entirely config-gated: config('tbtop-cms.revisions.keep', 25)
 * is the retention cap AND the on/off switch. A host that never published the
 * config section gets the default (25, on); a host that explicitly sets it to
 * 0 or null gets no snapshots at all, not even on create — snapshot() is the
 * single choke point for that, so no caller needs to duplicate the check.
 */
class PageRevisionWriter
{
    /**
     * Fields a revision snapshot cares about. Deliberately excludes status,
     * type, parent_id, sort_order, and slugs — those are structural/workflow
     * state, not editorial content, and restore() must not touch them (see
     * its docblock). Kept private-const rather than public: the observer only
     * needs wasChanged(EDITORIAL_FIELDS) via wasChangedEditorial() below, and
     * restore() applies the payload directly rather than iterating this list.
     */
    private const EDITORIAL_FIELDS = [
        'name', 'title', 'heading', 'summary', 'body',
        'image', 'content', 'seo', 'is_indexable',
    ];

    /** @var list<string> */
    private const TRANSLATABLE_FIELDS = ['name', 'title', 'heading', 'summary', 'body'];

    /**
     * @return list<string> Fields the observer treats as "editorial" for the
     *                      purposes of its wasChanged() dedup check.
     */
    public static function editorialFields(): array
    {
        return self::EDITORIAL_FIELDS;
    }

    public function snapshot(Page $page, string $kind): void
    {
        $keep = self::keep();
        if ($keep === null) {
            return;
        }

        PageRevision::query()->create([
            'page_id' => $page->getKey(),
            // CurrentAdmin::user() narrows to Model (not Authenticatable), so
            // getKey() — not getAuthIdentifier() — is what's typed; for any
            // Eloquent user model the primary key is exactly what user_id
            // should reference anyway (mirrors UsersPage's own $currentId).
            'user_id' => CurrentAdmin::user()?->getKey(),
            'kind' => $kind,
            'payload' => $this->snapshotPayload($page),
            'created_at' => now(),
        ]);

        $this->prune($page, $keep);
    }

    /**
     * Applies a past snapshot's payload back onto $page and saves it.
     * status/type/parent_id/sort_order/slugs are untouched on purpose (see
     * EDITORIAL_FIELDS docblock) — restoring old content must not resurrect a
     * page's old workflow state or move it in the tree. Every payload read is
     * defensive (array_key_exists / ?? []) so an older payload missing a key
     * that didn't exist yet at snapshot time — the schema only grows fields
     * over time — restores the rest instead of throwing.
     */
    public function restore(Page $page, PageRevision $revision): void
    {
        if ($revision->page_id !== $page->getKey()) {
            throw new DomainException('The revision does not belong to this page.');
        }

        $payload = $revision->payload;

        foreach (self::TRANSLATABLE_FIELDS as $field) {
            if (array_key_exists($field, $payload) && is_array($payload[$field])) {
                $page->setTranslations($field, $payload[$field]);
            }
        }

        if (array_key_exists('image', $payload)) {
            $page->image = $payload['image'];
        }
        if (array_key_exists('content', $payload)) {
            $page->content = $payload['content'];
        }
        if (array_key_exists('seo', $payload)) {
            $page->seo = $payload['seo'];
        }
        if (array_key_exists('is_indexable', $payload)) {
            $page->is_indexable = (bool) $payload['is_indexable'];
        }

        // See Page::$pendingRestore's docblock for why this lives on the
        // model instance rather than on this writer.
        $page->pendingRestore = true;

        try {
            app(AuditContext::class)->during(
                'revision_restored',
                ['revision_id' => $revision->getKey()],
                static fn (): bool => $page->save(),
            );
        } finally {
            $page->pendingRestore = false;
        }

        event(new PageSaved($page, false));
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(Page $page): array
    {
        $payload = [];

        foreach (self::TRANSLATABLE_FIELDS as $field) {
            $payload[$field] = $page->getTranslations($field);
        }

        $payload['image'] = $page->image;
        $payload['content'] = $page->content;
        $payload['seo'] = $page->seo;
        $payload['is_indexable'] = (bool) $page->is_indexable;
        // Captured display-only (see class docblock on restore()): the
        // revisions tab shows what status the page had at snapshot time, but
        // restore() never writes it back.
        $payload['status'] = $page->status->value;

        return $payload;
    }

    /**
     * Keeps the newest $keep rows for $page, deleting anything older. Runs
     * after every snapshot insert rather than on a schedule — revisions are
     * low-volume (one per save at most, deduped by the observer), so pruning
     * per-write is cheap and keeps the table bounded without a separate job.
     */
    private function prune(Page $page, int $keep): void
    {
        // whereNotIn(keepIds) rather than an offset-based "skip the newest
        // N" query: sqlite rejects a bare OFFSET without a LIMIT, and this
        // reads just as clearly — delete everything for this page that isn't
        // one of the newest $keep rows.
        $keepIds = PageRevision::query()
            ->where('page_id', $page->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('id');

        PageRevision::query()
            ->where('page_id', $page->getKey())
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /** Null means the feature is off (0 or unset/null keep value). */
    private static function keep(): ?int
    {
        $keep = (int) config('tbtop-cms.revisions.keep', 25);

        return $keep > 0 ? $keep : null;
    }
}
