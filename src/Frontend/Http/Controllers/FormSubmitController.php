<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Frontend\Form\FormBlockLocator;
use Tbtop\Cms\Frontend\Form\LocatedFormBlock;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

class FormSubmitController
{
    public function __construct(private readonly FormBlockLocator $locator) {}

    public function __invoke(Request $request, string $page, string $block): RedirectResponse
    {
        $model = Page::query()->where('status', PageStatus::Published)->find($page);

        if (! $model instanceof Page) {
            throw new NotFoundHttpException;
        }

        $located = $this->locator->locate($model, $block);

        if (! $located instanceof LocatedFormBlock) {
            throw new NotFoundHttpException;
        }

        $payload = $request->validate($located->block->submitRules($located->data, $located->context));
        $located->block->handleSubmit($payload, $located->data, $located->context);

        return back();
    }
}
