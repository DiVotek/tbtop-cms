<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Str;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Layout\Models\Layout;

class LayoutForm
{
    /** @return list<Node> */
    public static function fields(S $s): array
    {
        return [BlockContentField::make($s)];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, string $slot): Layout
    {
        return Layout::query()->updateOrCreate(
            ['slot' => $slot],
            ['content' => BlockContentField::toEnvelope($form['content'] ?? null)],
        );
    }

    /** @return array<string, mixed> */
    public static function loadRow(string $slot): array
    {
        $layout = Layout::query()->where('slot', $slot)->first();

        return ['content' => BlockContentField::toRows($layout?->content)];
    }

    public static function label(string $slot): string
    {
        $key = 'tbtop-cms::layout.slots.' . $slot;
        $label = __($key);

        return $label === $key ? Str::ucfirst($slot) : $label;
    }
}
