<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * The right sidebar column on PageEditPage: publication status, hierarchy
 * (type/parent), and manual sort order — split out of the "General" tab
 * into their own titled sections.
 */
final class PageEditSidebar
{
    public static function build(S $s, Page $page): Node
    {
        $isSourceBacked = app(PageTypeRegistry::class)->isSourceBacked($page->type);

        return $s->stack([
            $s->section(['title' => __('tbtop-cms::pages.sections.publication'), 'variant' => 'card'], [
                $s->select('status')->label(__('tbtop-cms::pages.fields.status'))
                    ->required()->options(PageFormOptions::statuses()),
                // boolean, not checkbox: renders as a switch on the client
                $s->boolean('is_indexable')->label(__('tbtop-cms::pages.fields.is_indexable')),
                ...self::schedulingFields($s),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.structure'), 'variant' => 'card'], [
                // The type select is not offered for a source-backed page —
                // its type is owned by the sync, not editorially chosen —
                // and content-only pages never see a source-backed type as
                // an option (PageTypeRegistry::isSourceBacked()).
                ...($isSourceBacked ? [] : [
                    $s->select('type')->label(__('tbtop-cms::pages.fields.type'))
                        ->required()->options(PageFormOptions::types(includeSourceBacked: false)),
                    ...PageForm::subtypeField($s, $page->type, isStored: true),
                ]),
                // Excludes the page itself and its descendants — placing it
                // under its own subtree is a cycle PageFormGuards::parent()
                // rejects on submit; excluding here keeps the picker from
                // offering an option that would only bounce back as an error.
                $s->relation('parent_id')->label(__('tbtop-cms::pages.fields.parent'))
                    ->query(static fn (): Builder => PageForm::parentQuery($page))
                    ->labelKey('name')->searchable(),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.ordering'), 'variant' => 'card'], [
                $s->number('sort_order')->label(__('tbtop-cms::pages.fields.sort_order')),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.image'), 'variant' => 'card'], [
                $s->media('image')->label(__('tbtop-cms::pages.fields.image'))->variant('preview'),
            ]),
            ...($isSourceBacked ? [PageEditSourceCard::build($s, $page)] : []),
        ]);
    }

    /**
     * Only meaningful once status is published — a draft has no schedule to
     * set. hiddenIf keeps them in the tree (so PageForm::save() still
     * receives and can clear them) but out of the rendered form while
     * status !== published.
     *
     * @return list<Field>
     */
    private static function schedulingFields(S $s): array
    {
        $hiddenUnlessPublished = Cond::neq('status', PageStatus::Published->value);

        return [
            $s->datetime('published_at')->label(__('tbtop-cms::pages.fields.published_at'))
                ->helperText(__('tbtop-cms::pages.fields.published_at_hint'))
                ->hiddenIf($hiddenUnlessPublished),
            $s->datetime('unpublish_at')->label(__('tbtop-cms::pages.fields.unpublish_at'))
                ->helperText(__('tbtop-cms::pages.fields.unpublish_at_hint'))
                ->hiddenIf($hiddenUnlessPublished),
        ];
    }
}
