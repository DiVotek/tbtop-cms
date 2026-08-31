<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Str;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\Models\Layout;

class LayoutForm
{
    /** @param array<string, mixed> $form */
    public static function save(array $form, string $slot): Layout
    {
        $layout = Layout::query()->updateOrCreate(
            ['slot' => $slot],
            ['content' => BlockContent::toEnvelope($form['content'] ?? null)],
        );

        event(new LayoutSaved($layout));

        return $layout;
    }

    /** @return array<string, mixed> */
    public static function loadRow(string $slot): array
    {
        $layout = Layout::query()->where('slot', $slot)->first();

        return ['content' => BlockContent::toRows($layout?->content)];
    }

    public static function label(string $slot): string
    {
        $key = 'tbtop-cms::layout.slots.' . $slot;
        $label = __($key);

        return $label === $key ? Str::ucfirst($slot) : $label;
    }
}
