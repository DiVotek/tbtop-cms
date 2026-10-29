<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Http\Requests\PublishPageRequest;
use Tbtop\Cms\Api\Http\Requests\StorePageRequest;
use Tbtop\Cms\Api\Http\Requests\UpdatePageRequest;
use Tbtop\Cms\Api\PageApiWriter;
use Tbtop\Cms\Api\PageResource;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

final readonly class PagesController
{
    private const int PER_PAGE = 50;

    public function __construct(private PageResource $resource, private PageApiWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'parent_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::enum(PageStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $pages = ApiAccess::scope($request)->apply(Page::query())
            ->with('slugs')
            ->when(isset($filters['parent_id']), static fn ($q) => $q->where('parent_id', (int) $filters['parent_id']))
            ->when(isset($filters['type']), static fn ($q) => $q->where('type', (string) $filters['type']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'data' => $this->resource->collection(array_values($pages->items())),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
            ],
        ]);
    }

    public function show(Request $request, string $page): JsonResponse
    {
        return response()->json($this->resource->toArray(ApiAccess::page($request, $page, isWrite: false)));
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        return response()->json($this->resource->toArray($this->writer->create($request->validated())), 201);
    }

    public function update(UpdatePageRequest $request): JsonResponse
    {
        return response()->json($this->resource->toArray($this->writer->update($request->page(), $request->validated())));
    }

    public function publish(PublishPageRequest $request, string $page): JsonResponse
    {
        $model = ApiAccess::page($request, $page, isWrite: true);

        return response()->json($this->resource->toArray($this->writer->publish($model, $request->publishedAt())));
    }

    public function destroy(Request $request, string $page): Response
    {
        $this->writer->delete(ApiAccess::page($request, $page, isWrite: true));

        return response()->noContent();
    }
}
