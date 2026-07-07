<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Frontend\Form\FormBlockLocator;
use Tbtop\Cms\Frontend\Form\LocatedFormBlock;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

class FormSubmitController
{
    public function __construct(
        private readonly FormBlockLocator $locator,
        private readonly Languages $languages,
    ) {}

    public function __invoke(Request $request, string $locale, string $page, string $block): RedirectResponse
    {
        $this->applyLocale($locale);

        $model = Page::query()->where('status', PageStatus::Published)->find($page);

        if (! $model instanceof Page) {
            throw new NotFoundHttpException;
        }

        $located = $this->locator->locate($model, $block);

        if (! $located instanceof LocatedFormBlock) {
            throw new NotFoundHttpException;
        }

        $payload = $request->validate($located->block->submitRules($located->data, $located->context));

        $submission = $this->store($model, $block, $located, $payload);

        try {
            $located->block->handleSubmit($payload, $located->data, $located->context);
        } catch (Throwable $e) {
            Log::error('form.submit.failed', ['page' => $model->getKey(), 'block' => $block, 'error' => $e->getMessage()]);

            $submission?->forceFill(['status' => SubmissionStatus::Failed, 'error' => $e->getMessage()])->save();

            return back()->withErrors(['form' => 'Sorry, we could not process your submission. Please try again.']);
        }

        $submission?->forceFill(['status' => SubmissionStatus::Handled, 'handled_at' => now(), 'error' => null])->save();

        return back();
    }

    /** @param array<string, mixed> $payload */
    private function store(Page $model, string $block, LocatedFormBlock $located, array $payload): ?FormSubmission
    {
        if (! config('tbtop-cms.forms.store', true)) {
            return null;
        }

        $kind = $located->block instanceof Block ? $located->block->kind() : $located->block::class;

        return FormSubmission::query()->create([
            'page_id' => $model->getKey(),
            'block_id' => $block,
            'kind' => $kind,
            'locale' => $located->context->locale,
            'payload' => $payload,
            'status' => SubmissionStatus::Pending,
        ]);
    }

    private function applyLocale(string $locale): void
    {
        $active = array_column($this->languages->frontendActive(), 'locale');

        if (in_array($locale, $active, true)) {
            $this->languages->setCurrent($locale);
        }
    }
}
