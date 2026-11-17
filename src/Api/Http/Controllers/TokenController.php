<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\TokenAction;

/** `GET /token`: what the calling token reaches, so a client learns its bounds before its first 403. Empty `fields` means every field. */
final class TokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        $scope = ApiAccess::scope($request);

        return response()->json([
            'pages' => $scope->pageIds,
            'trees' => $scope->treeIds,
            'parents' => $scope->parentIds,
            'types' => $scope->childTypes,
            'actions' => array_map(static fn (TokenAction $action): string => $action->value, $scope->actions),
            'fields' => $scope->writableGroups(),
        ]);
    }
}
