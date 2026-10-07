<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Admin\Panels\CurrentPanel;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * Section abilities for tbtop/admin's media API (tbtop.{panel}.media.*),
 * whose controllers check no gate of their own. Reading and adding is open
 * to every section with a media field, so the picker keeps working for a
 * page editor; changing or deleting an existing file or folder needs
 * tbtop-cms.media. A media route missing from the map needs media too, so a
 * route tbtop/admin adds later is closed until someone maps it.
 *
 * Only active with tbtop-cms.roles on — with roles off the API stays open
 * to every admin, as every section is.
 */
class AuthorizeMediaApi
{
    /** Abilities that each open the read/add routes: the sections with a media field. */
    public const array PICKER_ABILITIES = [
        'tbtop-cms.media',
        'tbtop-cms.pages',
        'tbtop-cms.settings',
        'tbtop-cms.design',
    ];

    /**
     * Route name (after "tbtop.{panel}.media.") => abilities, any of which
     * lets the request through.
     *
     * @var array<string, list<string>>
     */
    public const array ROUTES = [
        'index' => self::PICKER_ABILITIES,
        'show' => self::PICKER_ABILITIES,
        'download' => self::PICKER_ABILITIES,
        'folders.index' => self::PICKER_ABILITIES,
        'upload' => self::PICKER_ABILITIES,
        'import-url' => self::PICKER_ABILITIES,
        'folders.store' => self::PICKER_ABILITIES,
        'update' => ['tbtop-cms.media'],
        'replace' => ['tbtop-cms.media'],
        'destroy' => ['tbtop-cms.media'],
        'folders.update' => ['tbtop-cms.media'],
        'folders.destroy' => ['tbtop-cms.media'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $abilities = $this->abilities($request);

        if ($abilities !== null) {
            $user = $request->user(CurrentAdmin::guard());

            // A guest is the auth middleware's to reject, not ours.
            if ($user !== null && ! Gate::forUser($user)->any($abilities)) {
                throw new AuthorizationException;
            }
        }

        return $next($request);
    }

    /** @return list<string>|null Null when the request is not a gated media route. */
    private function abilities(Request $request): ?array
    {
        if (! CmsAbilities::enabled()) {
            return null;
        }

        $panel = CurrentPanel::current();
        $name = $request->route()?->getName();
        if (! $panel instanceof CurrentPanel || $name === null) {
            return null;
        }

        $prefix = 'tbtop.' . $panel->id() . '.media.';
        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        return self::ROUTES[substr($name, strlen($prefix))] ?? ['tbtop-cms.media'];
    }
}
