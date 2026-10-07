<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Carbon;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\DisplayValueBlock;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Frontend\PreviewLink;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Models\Page;

/**
 * The status-bar card at the top of PageEditPage: status badge, updated/
 * created timestamps, Problem blocks (when any), view-on-site, and a
 * top-of-form save button mirroring the footer save.
 *
 * The badge shows the computed PageDisplayState (draft/scheduled/published/
 * expired), not the raw PageStatus (draft/published) — see
 * Page::displayState(). Every case must have a color entry here; adding a
 * case without one is a compile-time array-key error, which is the point.
 */
final class PageEditStatusBar
{
    /**
     * The admin DSL's badge Color enum has no "info" or "muted" token
     * (Gray/Success/Warning/Danger/Primary only — see Tbtop\Admin\Dsl\Color),
     * so scheduled/expired fall back to the closest supported tone: gray
     * reads as neutral/inactive for both "not yet" and "no longer".
     *
     * @var array<string, string>
     */
    private const STATUS_COLORS = [
        'draft' => 'warning',
        'scheduled' => 'gray',
        'published' => 'success',
        'expired' => 'gray',
    ];

    /** @param ActionBuilder|list<ActionBuilder> $saveTop */
    public static function build(S $s, Page $page, ?ActionBuilder $revisions, ActionBuilder $viewOnSite, ActionBuilder | array $saveTop, ?ActionBuilder $problemBlocks = null): Node
    {
        return $s->flex([
            self::statusBadge($s, $page),
            ...self::scheduleNote($s, $page),
            $s->unsavedIndicator(),
            $s->spacer(),
            ...self::timestamps($s, $page),
            ...($problemBlocks !== null ? [$problemBlocks] : []),
            ...($revisions !== null ? [$revisions] : []),
            $viewOnSite,
            ...self::previewLinkAction($s, $page),
            ...(is_array($saveTop) ? $saveTop : [$saveTop]),
        ], align: 'center', gap: 3, wrap: true, variant: 'card', class: 'text-xs');
    }

    /**
     * Shown whenever the page is not currently live: draft, scheduled
     * (published_at in the future), or expired (unpublish_at in the past) —
     * all three have something worth previewing ahead of/after the live
     * site. A live page has nothing to preview, so the action (and the
     * modal it opens) simply doesn't exist for it — mirrors canDelete()'s
     * server-side conditional-build pattern in PageEditPage rather than
     * hiding a built action client-side. An error page has no slug and no
     * URL of its own, so it gets no action either.
     *
     * One read-only field per site locale with a saved slug, in site-locale
     * order: a locale without a path has nothing to link to, and signing
     * its empty path would point the link at the home page.
     *
     * @return list<ActionBuilder>
     */
    private static function previewLinkAction(S $s, Page $page): array
    {
        if ($page->isLive() || $page->isErrorPage()) {
            return [];
        }

        $locales = self::previewLocales($page);

        $close = $s->action('pagePreviewLinkClose')->label(__('tbtop-cms::pages.preview.close'))
            ->handle(static fn (ActionCtx $ctx): Effects => Effects::make()->closeModal());

        $body = $locales === []
            ? [
                $s->displayText(__('tbtop-cms::pages.preview.no_slug'))->variant('muted'),
                $s->flex([$s->spacer(), $close], justify: 'end'),
            ]
            : [
                ...array_map(
                    static fn (string $locale, string $name): Field => $s->text(self::fieldName($locale))->label($name)->meta('disabled', true),
                    array_keys($locales),
                    array_values($locales),
                ),
                $s->displayText(__('tbtop-cms::pages.preview.helper', ['hours' => self::ttlHours()]))->variant('muted'),
                $s->flex([$s->spacer(), self::copyAction($s, $page, array_key_first($locales)), $close], justify: 'end'),
            ];

        // The signed URLs are only fetched via the action's query() below
        // (which runs when the modal opens) — this form carries no onSubmit
        // because there's nothing to save, only values to display and copy.
        $form = $s->form('pagePreviewLinkForm', $body)->guardUnsaved(false);

        return [
            $s->action('pagePreviewLink')->label(__('tbtop-cms::pages.actions.preview_link'))
                ->icon('eye')
                ->modal(__('tbtop-cms::pages.actions.preview_link_title'), $form)
                ->query(static fn (ActionCtx $ctx): array => array_combine(
                    array_map(self::fieldName(...), array_keys($locales)),
                    array_map(static fn (string $locale): string => app(PreviewLink::class)->for($page, $locale), array_keys($locales)),
                ), needs: []),
        ];
    }

    /**
     * Copies the first locale's link. Regenerates the signed URL (same
     * deterministic call as the modal's query()) instead of reading the
     * disabled field's displayed value.
     */
    private static function copyAction(S $s, Page $page, string $locale): ActionBuilder
    {
        return $s->action('pagePreviewLinkCopy')->label(__('tbtop-cms::pages.preview.copy'))
            ->handle(static fn (ActionCtx $ctx): Effects => Effects::make()->copyToClipboard(
                app(PreviewLink::class)->for($page, $locale),
            ));
    }

    /**
     * Site locales (frontendActive order) whose saved slug gives the page a
     * path — `!== null`, not truthiness: a system root's path is ''.
     *
     * @return array<string, string> locale => language name
     */
    private static function previewLocales(Page $page): array
    {
        $locales = [];

        foreach (app(Languages::class)->frontendActive() as $language) {
            if ($page->pathFor($language['locale']) !== null) {
                $locales[$language['locale']] = $language['name'];
            }
        }

        return $locales;
    }

    private static function fieldName(string $locale): string
    {
        return 'previewUrl_' . $locale;
    }

    private static function ttlHours(): int
    {
        return (int) config('tbtop-cms.preview.link_ttl_hours', 72);
    }

    private static function statusBadge(S $s, Page $page): DisplayValueBlock
    {
        $state = $page->displayState();
        $label = __('tbtop-cms::pages.status.' . $state->value);

        return $s->displayValue($label)->badge([$label => ['color' => self::colorFor($state)]]);
    }

    private static function colorFor(PageDisplayState $state): string
    {
        return self::STATUS_COLORS[$state->value];
    }

    /**
     * The badge alone doesn't say *when* a scheduled/expired page changes
     * state — this compact muted text supplies the datetime next to it, same
     * style as the updated/created timestamp groups below.
     *
     * @return list<TextBlock>
     */
    private static function scheduleNote(S $s, Page $page): array
    {
        return match ($page->displayState()) {
            PageDisplayState::Scheduled => [self::note($s, __('tbtop-cms::pages.status_bar.scheduled_for', [
                'datetime' => self::format($page->published_at),
            ]))],
            PageDisplayState::Expired => [self::note($s, __('tbtop-cms::pages.status_bar.expired_at', [
                'datetime' => self::format($page->unpublish_at),
            ]))],
            default => [],
        };
    }

    private static function note(S $s, string $text): TextBlock
    {
        return $s->displayText($text)->variant('muted');
    }

    private static function format(?Carbon $datetime): string
    {
        return $datetime?->format('d.m.Y H:i') ?? '';
    }

    /**
     * Compact icon'd pair instead of the old "Updated <datetime> Created
     * <datetime>" text: an icon communicates which timestamp this is without
     * spending horizontal space on the word — this row already competes with
     * the status badge, view-on-site, preview-link, revisions, and save
     * actions for the same line. Updated carries the full datetime plus its
     * author (who most recently touched the content); Created is date-only
     * with no author — the creator is one click away in the Revisions panel
     * (its oldest, kind=create row), so repeating it here would be the same
     * fact twice for less room to show it clearly.
     *
     * @return list<mixed>
     */
    private static function timestamps(S $s, Page $page): array
    {
        $nodes = [self::updatedGroup($s, $page)];

        if ($page->created_at !== null && ! $page->created_at->equalTo($page->updated_at)) {
            $nodes[] = self::createdGroup($s, $page);
        }

        return $nodes;
    }

    private static function updatedGroup(S $s, Page $page): Node
    {
        $children = [
            self::icon($s, 'pencil'),
            $s->displayValue($page->updated_at)->datetime('d.m.Y H:i'),
        ];

        $author = AuthorNames::label($page->updated_by, AuthorNames::lookup([$page->updated_by]));
        if ($author !== null) {
            // " · <author>" stays on the same line, muted, rather than a
            // second row — the goal is compactness, not a second fact
            // fighting the datetime for visual weight.
            $children[] = $s->displayText('· ' . $author)->variant('muted');
        }

        return $s->flex($children, align: 'center', gap: 1);
    }

    private static function createdGroup(S $s, Page $page): Node
    {
        return $s->flex([
            self::icon($s, 'calendar-plus'),
            $s->displayValue($page->created_at)->date('d.m.Y'),
        ], align: 'center', gap: 1);
    }

    /**
     * A bare icon, no visible text: DisplayValueBlock's icon() kind maps a
     * value to an {icon,color} entry and renders only the icon client-side
     * (IconMapCell) — the value itself ($name) never reaches the page, it's
     * just the lookup key matching its own single map entry.
     *
     * The entry MUST be the object form ['icon' => $name]: the PHP docblock
     * also allows a bare icon string, but the client's IconMapCell only reads
     * entry.icon — a string entry leaves it undefined and the cell falls back
     * to rendering the raw value as text (verified live: the status bar
     * showed the words "pencil"/"calendar-plus"). Admin-package doc/client
     * mismatch, noted in the audit backlog.
     */
    private static function icon(S $s, string $name): DisplayValueBlock
    {
        return $s->displayValue($name)->icon([$name => ['icon' => $name]]);
    }
}
