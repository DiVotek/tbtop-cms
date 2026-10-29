<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Tbtop\Cms\Page\Models\Page;

/** The page an API request names, found and held to the token's scope: 404 when there is none, 403 when the token may not reach it. */
final class ApiAccess
{
    public const string GUARD = 'cms-api';

    public static function scope(Request $request): TokenScope
    {
        $user = $request->user(self::GUARD);
        $token = is_object($user) && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        // A session or transient token carries no stored scope, so it reaches nothing.
        $abilities = $token instanceof PersonalAccessToken ? $token->getAttribute('abilities') : null;

        return TokenScope::fromAbilities(is_array($abilities) ? array_values($abilities) : []);
    }

    public static function page(Request $request, mixed $id, bool $isWrite): Page
    {
        $page = is_numeric($id) ? Page::query()->find((int) $id) : null;
        abort_if($page === null, 404, __('tbtop-cms::api.errors.not_found'));

        $scope = self::scope($request);
        abort_unless($isWrite ? $scope->writes($page) : $scope->reads($page), 403, __('tbtop-cms::api.errors.out_of_scope'));

        return $page;
    }
}
