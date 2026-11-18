<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\NotFound\Support\NotFoundHits;

/** The 404 log: read, and ignore or restore a hit. Rows are never deleted here, `cms:not-found:prune` does that. */
final class NotFoundController
{
    private const int PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['sometimes', Rule::in(['active', 'ignored', 'all'])],
            'min_hits' => ['sometimes', 'integer', 'min:1'],
            'since' => ['sometimes', 'date'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'has_redirect' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $state = (string) ($filters['state'] ?? 'active');

        $hits = NotFoundHits::withRedirectFlag()
            ->when($state !== 'all', static fn ($q) => $q->where('is_ignored', $state === 'ignored'))
            ->when(isset($filters['min_hits']), static fn ($q) => $q->where('hits', '>=', (int) $filters['min_hits']))
            ->when(isset($filters['since']), static fn ($q) => $q->where('last_seen_at', '>=', (string) $filters['since']))
            ->when(isset($filters['locale']), static fn ($q) => $q->where('locale', (string) $filters['locale']))
            ->when(isset($filters['has_redirect']), static fn ($q) => $q->{$request->boolean('has_redirect') ? 'whereExists' : 'whereNotExists'}(NotFoundHits::redirectExists()))
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'data' => array_map($this->present(...), array_values($hits->items())),
            'meta' => [
                'current_page' => $hits->currentPage(),
                'last_page' => $hits->lastPage(),
                'per_page' => $hits->perPage(),
                'total' => $hits->total(),
            ],
        ]);
    }

    public function ignore(string $hit): JsonResponse
    {
        return $this->setIgnored($hit, true);
    }

    public function unignore(string $hit): JsonResponse
    {
        return $this->setIgnored($hit, false);
    }

    private function setIgnored(string $id, bool $isIgnored): JsonResponse
    {
        $hit = NotFoundHit::query()->find((int) $id);
        abort_if($hit === null, 404, __('tbtop-cms::api.errors.not_found'));

        NotFoundHits::setIgnored($hit, $isIgnored);

        return response()->json($this->present(NotFoundHits::withRedirectFlag()->findOrFail($hit->getKey())));
    }

    /** @return array<string, mixed> */
    private function present(NotFoundHit $hit): array
    {
        return [
            'id' => $hit->id,
            'locale' => $hit->locale,
            'path' => $hit->path,
            'hits' => $hit->hits,
            'last_referer' => $hit->last_referer,
            'last_user_agent' => $hit->last_user_agent,
            'first_seen_at' => $hit->first_seen_at->toIso8601String(),
            'last_seen_at' => $hit->last_seen_at->toIso8601String(),
            'is_ignored' => $hit->is_ignored,
            'has_redirect' => (bool) $hit->getAttribute('has_redirect'),
        ];
    }
}
