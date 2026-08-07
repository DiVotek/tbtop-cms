<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Template\Models\Template;

class TemplateForm
{
    /** @return list<Node> */
    public static function fields(S $s): array
    {
        return [
            $s->section(['title' => __('tbtop-cms::templates.sections.template')], [
                $s->text('name')->label(__('tbtop-cms::templates.fields.name'))->required(),
            ]),
            BlockContentField::make($s),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Template
    {
        $template = $id !== null ? Template::query()->findOrFail($id) : new Template;
        $template->fill([
            'name' => (string) ($form['name'] ?? ''),
            'content' => BlockContentField::toEnvelope($form['content'] ?? null),
        ]);
        $template->save();

        return $template;
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $template = Template::query()->findOrFail($id);

        return [
            'name' => $template->name,
            'content' => BlockContentField::toRows($template->content),
        ];
    }
}
