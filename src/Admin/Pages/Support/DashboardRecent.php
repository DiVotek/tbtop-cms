<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\Route;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\LeadIdentity;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Models\Page;

/** Row 2 — recently updated pages and the latest form submissions, each a card-list. */
class DashboardRecent
{
    private const LIMIT = 5;

    /** @return list<Node> */
    public static function cards(S $s, bool $showSubmissions = true): array
    {
        return $showSubmissions
            ? [self::recentPagesCard($s), self::latestSubmissionsCard($s)]
            : [self::recentPagesCard($s)];
    }

    private static function recentPagesCard(S $s): Node
    {
        $list = $s->list('dashboardRecentPages')->items(self::recentPagesItems(...));

        return $s->section([
            'title' => __('tbtop-cms::dashboard.recent.pages_title'),
            'variant' => 'card',
            ...self::actionFor($s, 'dashboardOpenPages', 'tbtop-cms::dashboard.recent.open_pages', 'tbtop.cms.pages-page'),
        ], [$list]);
    }

    private static function latestSubmissionsCard(S $s): Node
    {
        $list = $s->list('dashboardLatestSubmissions')->items(self::latestSubmissionsItems(...));

        return $s->section([
            'title' => __('tbtop-cms::dashboard.recent.submissions_title'),
            'variant' => 'card',
            ...self::actionFor($s, 'dashboardOpenSubmissions', 'tbtop-cms::dashboard.recent.open_submissions', 'tbtop.cms.form-submissions-page'),
        ], [$list]);
    }

    /** @return list<array{title: string, meta: string, color: string, url?: string}> */
    private static function recentPagesItems(): array
    {
        $hasEdit = Route::has('tbtop.cms.page-edit-page');

        return Page::query()->orderByDesc('updated_at')->limit(self::LIMIT)->get()
            ->map(static fn (Page $page): array => [
                'title' => (string) $page->getTranslation('name', app()->getLocale()),
                'meta' => optional($page->updated_at)->diffForHumans() ?? '',
                'color' => self::displayStateColor($page->displayState()),
                ...($hasEdit ? ['url' => route('tbtop.cms.page-edit-page', ['page' => $page->id])] : []),
            ])
            ->all();
    }

    /**
     * Only currently-live pages read as success; draft, scheduled, and
     * expired all fall back to muted — a scheduled/expired page is not
     * currently visible on the frontend any more than a draft is, so it
     * should not read as the same "live" green.
     */
    private static function displayStateColor(PageDisplayState $state): string
    {
        return $state === PageDisplayState::Published ? 'success' : 'muted';
    }

    /**
     * Title is now the lead's identity (5.4 — "who" outranks "which form"
     * here too, same reasoning as FormSubmissionsPage's Lead column), with
     * the form kind folded into meta alongside the relative time rather than
     * dropped — it's still useful context, just secondary.
     *
     * @return list<array{title: string, meta: string, color: string, url?: string}>
     */
    private static function latestSubmissionsItems(): array
    {
        $hasDetail = Route::has('tbtop.cms.form-submission-page');

        return FormSubmission::query()->orderByDesc('created_at')->limit(self::LIMIT)->get()
            ->map(static fn (FormSubmission $submission): array => [
                'title' => LeadIdentity::label($submission->payload) ?? $submission->kind,
                'meta' => $submission->kind . ' · ' . (optional($submission->created_at)->diffForHumans() ?? ''),
                'color' => self::leadStatusColor($submission->lead_status),
                ...($hasDetail ? ['url' => route('tbtop.cms.form-submission-page', ['submission' => $submission->id])] : []),
            ])
            ->all();
    }

    /** New submissions need attention; processed ones are resolved; read/discarded fade to muted. */
    private static function leadStatusColor(LeadStatus $status): string
    {
        return match ($status) {
            LeadStatus::New => 'danger',
            LeadStatus::Processed => 'success',
            LeadStatus::Read, LeadStatus::Discarded => 'muted',
        };
    }

    /** @return array{actions?: list<ActionBuilder>} */
    private static function actionFor(S $s, string $name, string $labelKey, string $route): array
    {
        return Route::has($route)
            ? ['actions' => [$s->action($name)->label(__($labelKey))->url(route($route))->link()->size('sm')]]
            : [];
    }
}
