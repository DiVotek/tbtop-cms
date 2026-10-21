<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

/**
 * The SEO badge on the pages list: a coarse "is anything obviously missing"
 * hint, computed in SQL over the whole list (PageListState::seoStateSql()). The
 * authoritative per-page verdict lives in SeoChecklist::for().
 *
 * Backed by a number rather than a name so the column can be sorted. The badge
 * has one useful order — worst first, the "what needs work" sweep — and the
 * names sort into `fail`, `pass`, `warn`, which interleaves the broken pages
 * with the healthy ones. The rank is the value the query selects and the
 * client sorts on; label() turns it back into words for display.
 *
 * Ascending rank is worst-to-best, so the plain ASC click lands on the order
 * an editor wants.
 */
enum SeoState: int
{
    case Fail = 0;
    case Warn = 1;
    case Pass = 2;

    /** The translation key suffix — the badge's identity in words. */
    public function name(): string
    {
        return match ($this) {
            self::Fail => 'fail',
            self::Warn => 'warn',
            self::Pass => 'pass',
        };
    }

    public function label(): string
    {
        return __('tbtop-cms::pages.seo_state.' . $this->name());
    }

    /**
     * The label for a raw rank as it comes back from the query.
     *
     * Anything unrecognized falls through to an empty badge rather than
     * inventing a verdict: a value this enum does not know is a bug in the
     * SQL, and showing "Good" for it would hide broken pages.
     */
    public static function labelFor(mixed $rank): string
    {
        return self::tryFrom((int) $rank)?->label() ?? '';
    }
}
