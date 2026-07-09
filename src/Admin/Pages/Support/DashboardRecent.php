<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\Route;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/** Row 2 — recently updated pages and the latest form submissions, each a card-list. */
class DashboardRecent
{
    private const LIMIT = 5;

    /** @return list<Node> */
    public static function cards(S $s): array
    {
        return [self::recentPagesCard($s), self::latestSubmissionsCard($s)];
    }

    private static function recentPagesCard(S $s): Node
    {
        $list = $s->list('dashboardRecentPages')->items(self::recentPagesItems(...));

        return $s->section([
            'title' => __('tbtop-cms::dashboard.recent.pages_title'),
            'variant' => 'card',
            ...self::actionFor('tbtop-cms::dashboard.recent.open_pages', 'tbtop.cms.pages-page'),
        ], [$list]);
    }

    private static function latestSubmissionsCard(S $s): Node
    {
        $list = $s->list('dashboardLatestSubmissions')->items(self::latestSubmissionsItems(...));

        return $s->section([
            'title' => __('tbtop-cms::dashboard.recent.submissions_title'),
            'variant' => 'card',
            ...self::actionFor('tbtop-cms::dashboard.recent.open_submissions', 'tbtop.cms.form-submissions-page'),
        ], [$list]);
    }

    /** @return list<array{title: string, meta: string, color: string, url?: string}> */
    private static function recentPagesItems(): array
    {
        $url = self::pagesUrl();

        return Page::query()->orderByDesc('updated_at')->limit(self::LIMIT)->get()
            ->map(static fn (Page $page): array => [
                'title' => (string) $page->getTranslation('name', app()->getLocale()),
                'meta' => optional($page->updated_at)->diffForHumans() ?? '',
                'color' => $page->status === PageStatus::Published ? 'success' : 'muted',
                ...($url !== null ? ['url' => $url] : []),
            ])
            ->all();
    }

    /** @return list<array{title: string, meta: string, color: string, url?: string}> */
    private static function latestSubmissionsItems(): array
    {
        $url = self::submissionsUrl();

        return FormSubmission::query()->orderByDesc('created_at')->limit(self::LIMIT)->get()
            ->map(static fn (FormSubmission $submission): array => [
                'title' => $submission->kind,
                'meta' => optional($submission->created_at)->diffForHumans() ?? '',
                'color' => self::leadStatusColor($submission->lead_status),
                ...($url !== null ? ['url' => $url] : []),
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

    private static function pagesUrl(): ?string
    {
        return Route::has('tbtop.cms.pages-page') ? route('tbtop.cms.pages-page') : null;
    }

    private static function submissionsUrl(): ?string
    {
        return Route::has('tbtop.cms.form-submissions-page') ? route('tbtop.cms.form-submissions-page') : null;
    }

    /** @return array{action?: array{label: string, url: string}} */
    private static function actionFor(string $labelKey, string $route): array
    {
        return Route::has($route)
            ? ['action' => ['label' => __($labelKey), 'url' => route($route)]]
            : [];
    }
}
