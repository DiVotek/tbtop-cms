<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Support;

use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Notifications\Format;

/** Default binding until cms-4 adds a real template store: every type renders its built-in view. */
final class NullTemplateStore implements TemplateStore
{
    public function override(string $type, Format $format, string $locale): ?string
    {
        return null;
    }
}
