<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Support;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Redirect\Models\Redirect;

/** What the admin's 404 log and the API's both ask of the hit table. */
final class NotFoundHits
{
    /**
     * The hit's own locale or a locale-agnostic redirect, the way
     * RedirectResolver::match() resolves it.
     *
     * @return Builder<Redirect>
     */
    public static function redirectExists(): Builder
    {
        return Redirect::query()->selectRaw('1')
            ->whereColumn('tbtop_redirects.from_path', 'tbtop_not_found_hits.path')
            ->where(static fn (Builder $q): Builder => $q
                ->whereColumn('tbtop_redirects.locale', 'tbtop_not_found_hits.locale')
                ->orWhereNull('tbtop_redirects.locale'))
            ->limit(1);
    }

    /** @return Builder<NotFoundHit> */
    public static function withRedirectFlag(): Builder
    {
        return NotFoundHit::query()
            ->select('tbtop_not_found_hits.*')
            ->selectSub(self::redirectExists(), 'has_redirect');
    }

    /** Whether the flag changed: a hit already in that state writes no audit entry. */
    public static function setIgnored(NotFoundHit $hit, bool $isIgnored): bool
    {
        if ($hit->is_ignored === $isIgnored) {
            return false;
        }

        $hit->forceFill(['is_ignored' => $isIgnored])->save();
        app(AuditContext::class)->log(
            $hit,
            $isIgnored ? 'ignored' : 'unignored',
            $hit->path,
            context: ['path' => $hit->path, 'locale' => $hit->locale],
        );

        return true;
    }
}
