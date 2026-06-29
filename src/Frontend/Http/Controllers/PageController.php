<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Page\Models\Page;

class PageController
{
    public function __construct(
        private readonly PageResolver $resolver,
        private readonly Breadcrumbs $breadcrumbs,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $locale = (string) app()->getLocale();
        $path = (string) $request->attributes->get('cms_path', '');

        $page = $this->resolver->resolve($path, $locale);

        if (! $page instanceof Page) {
            throw new NotFoundHttpException;
        }

        return response()->json($this->payload($page, $locale, $path));
    }

    /** @return array<string, mixed> */
    private function payload(Page $page, string $locale, string $path): array
    {
        return [
            'page' => [
                'id' => $page->getKey(),
                'type' => $page->type,
                'status' => $page->status->value,
                'name' => $page->getTranslation('name', $locale),
                'title' => $page->getTranslation('title', $locale),
                'heading' => $page->getTranslation('heading', $locale),
                'summary' => $page->getTranslation('summary', $locale),
                'content' => $page->getTranslation('content', $locale),
                'seo' => $page->seo,
            ],
            'locale' => $locale,
            'path' => $path,
            'breadcrumbs' => $this->breadcrumbs->forPage($page, $locale),
        ];
    }
}
