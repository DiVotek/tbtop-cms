<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ConcreteView;
use InvalidArgumentException;
use LogicException;

/**
 * Renders a notification's view into a Rendered subject/body, with the
 * payload DTO's public properties exposed as template variables. The view
 * is expected to define a `subject` section (`@section('subject', ...)`),
 * handed to channels entity-decoded as plain text; its absence just yields an empty subject — a short-format channel like
 * telegram or bell has no use for one.
 *
 * $template accepts either a registered view name or inline Blade source
 * (a merchant-edited override — see DatabaseTemplateStore::compose()):
 * a string that looks like a view name AND resolves via View::exists() is
 * treated as one, otherwise it's compiled as inline source.
 */
class TemplateRenderer
{
    private const VIEW_NAME_PATTERN = '/^[A-Za-z0-9_\-:.\/]+$/';

    public function render(string $template, object $payload, string $locale): Rendered
    {
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
            $data = get_object_vars($payload);
            $instance = $this->isViewName($template)
                ? View::make($template, $data)
                : View::file($this->compiledInlinePath($template), $data);

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

            // Blade escapes the subject section like any echo, but a subject is
            // plain text for every channel (a mail header, a bell title, a
            // host's SMS) — decode it once here so no channel has to.
            return new Rendered(trim(html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $body, $data);
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

    private function isViewName(string $template): bool
    {
        return preg_match(self::VIEW_NAME_PATTERN, $template) === 1 && View::exists($template);
    }

    /** Compiles inline Blade source to a stable, content-addressed file so repeated renders reuse it. */
    private function compiledInlinePath(string $source): string
    {
        $path = rtrim((string) config('view.compiled'), '/') . '/tbtop-notification-' . hash('sha256', $source) . '.blade.php';

        if (! File::exists($path)) {
            File::put($path, $source);
        }

        return $path;
    }
}
