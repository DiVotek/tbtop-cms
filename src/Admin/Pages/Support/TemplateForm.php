<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Template\Models\Template;

class TemplateForm
{
    /** @return list<Node> */
    public static function fields(S $s): array
    {
        return [
            $s->section(['title' => 'Template'], [
                $s->text('name')->label('Name')->required(),
                $s->textarea('content')->label('Blocks (JSON)'),
            ]),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Template
    {
        $template = $id !== null ? Template::query()->findOrFail($id) : new Template;
        $template->fill([
            'name' => (string) ($form['name'] ?? ''),
            'content' => self::decodeContent($form['content'] ?? null),
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
            'content' => self::encodeContent($template->content),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function pageOptions(): array
    {
        $default = app(Languages::class)->defaultLocale();
        $options = [];

        foreach (Page::query()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            $name = $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey());
            $options[] = ['value' => (string) $page->getKey(), 'label' => $name];
        }

        return $options;
    }

    /** @return list<mixed> */
    private static function decodeContent(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['content' => 'Blocks must be a valid JSON array.']);
        }

        return array_values($decoded);
    }

    private static function encodeContent(mixed $content): string
    {
        if (! is_array($content) || $content === []) {
            return '';
        }

        return (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
