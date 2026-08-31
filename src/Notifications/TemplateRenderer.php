<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ConcreteView;
use InvalidArgumentException;
use LogicException;

/**
 * Renders a notification's view into a Rendered subject/body, with the
 * payload DTO's public properties exposed as template variables. The view
 * is expected to define a `subject` section (`@section('subject', ...)`);
 * its absence just yields an empty subject — a short-format channel like
 * telegram or bell has no use for one.
 */
class TemplateRenderer
{
    public function render(string $view, object $payload, string $locale): Rendered
    {
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
            $data = get_object_vars($payload);
            $instance = View::make($view, $data);

            // Illuminate\Contracts\View\View only promises a zero-arg
            // render(): string; the section-capturing callback below is a
            // concrete Illuminate\View\View feature (what View::make()
            // always actually returns in a Laravel app), so this narrows
            // explicitly rather than relying on the interface's contract.
            if (! $instance instanceof ConcreteView) {
                throw new LogicException('Expected ' . ConcreteView::class . ', got ' . $instance::class . '.');
            }

            // render()'s optional callback runs after Blade has evaluated the
            // view (so @section pushes are populated) but before the factory
            // flushes them — the only point body and subject are both
            // available together.
            /** @var array{0: string, 1: string} $result */
            $result = $instance->render(
                fn ($rendered, string $contents): array => [$contents, (string) ($rendered->getFactory()->getSections()['subject'] ?? '')],
            );
            [$body, $subject] = $result;

            return new Rendered(trim($subject), $body, $data);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function assertViewExists(string $view): void
    {
        if (! View::exists($view)) {
            throw new InvalidArgumentException("Notification view [{$view}] does not exist.");
        }
    }
}
