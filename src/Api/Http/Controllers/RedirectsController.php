<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Http\Requests\StoreRedirectRequest;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\NotFound\Support\NotFoundHits;
use Tbtop\Cms\Redirect\Models\Redirect;

/** Redirects over the API: read all, create through the admin's own form path, delete only the ones the calling token created. */
final class RedirectsController
{
    private const int PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'locale' => ['sometimes', 'nullable', 'string', 'max:5'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $tokenId = ApiAccess::tokenId($request);

        $redirects = Redirect::query()
            ->when(filled($filters['locale'] ?? null), static fn ($q) => $q->where('locale', (string) $filters['locale']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'data' => array_map(fn (Redirect $redirect): array => $this->present($redirect, $tokenId), array_values($redirects->items())),
            'meta' => [
                'current_page' => $redirects->currentPage(),
                'last_page' => $redirects->lastPage(),
                'per_page' => $redirects->perPage(),
                'total' => $redirects->total(),
            ],
        ]);
    }

    public function store(StoreRedirectRequest $request): JsonResponse
    {
        $data = $request->redirect();
        $tokenId = ApiAccess::tokenId($request);

        $redirect = DB::transaction(function () use ($data, $tokenId): Redirect {
            $redirect = RedirectForm::save($data);
            // A query, not a model save: the row's audit entry is the create, not a second edit.
            Redirect::query()->whereKey($redirect->getKey())->update(['created_via_token_id' => $tokenId]);

            $hit = NotFoundHit::query()->where('locale', $redirect->locale)->where('path', $redirect->from_path)->first();
            if ($hit !== null) {
                NotFoundHits::setIgnored($hit, true);
            }

            return $redirect->refresh();
        });

        return response()->json($this->present($redirect, $tokenId), 201);
    }

    public function destroy(Request $request, string $redirect): Response
    {
        $model = Redirect::query()->find((int) $redirect);
        abort_if($model === null, 404, __('tbtop-cms::api.errors.not_found'));

        $tokenId = ApiAccess::tokenId($request);
        abort_unless($tokenId !== null && $model->created_via_token_id === $tokenId, 403, __('tbtop-cms::api.errors.not_own_redirect'));

        $model->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Redirect $redirect, ?int $tokenId): array
    {
        return [
            'id' => $redirect->id,
            'locale' => $redirect->locale,
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
            'status_code' => $redirect->status_code,
            'is_auto' => $redirect->is_auto,
            'is_own' => $tokenId !== null && $redirect->created_via_token_id === $tokenId,
            'created_at' => $redirect->created_at?->toIso8601String(),
        ];
    }
}
