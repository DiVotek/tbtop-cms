<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Page\PageTypeRegistry;

/** `GET /types/{type}/fields`: what a page of this type (a base, or a subtype with its base) takes, for the types the token may create. */
final class TypeFieldsController
{
    public function __invoke(Request $request, string $type, PageTypeRegistry $registry): JsonResponse
    {
        abort_unless($registry->has($type), 404, __('tbtop-cms::api.errors.type_unknown'));
        abort_unless(ApiAccess::scope($request)->allowsType($registry->get($type)->base()), 403, __('tbtop-cms::api.errors.out_of_scope'));

        return response()->json(PageFields::forTypeKey($type)->describe());
    }
}
