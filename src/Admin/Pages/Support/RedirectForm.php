<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Redirect\Support\RedirectPath;

class RedirectForm
{
    /** @return list<Field> */
    public static function fields(S $s): array
    {
        return [
            $s->text('from_path')->label('From path')->required()
                ->helperText('Path without locale prefix, e.g. old-page.'),
            $s->text('to_path')->label('To URL or path')->required()
                ->helperText('Local path or full external URL.'),
            $s->select('locale')->label('Locale')->options(RedirectFormOptions::locales()),
            $s->select('status_code')->label('Status')->required()->options(RedirectFormOptions::statuses()),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Redirect
    {
        $locale = self::nullableString($form['locale'] ?? null);
        $from = RedirectPath::normalize((string) ($form['from_path'] ?? ''));
        $to = self::normalizeTarget((string) ($form['to_path'] ?? ''));
        $status = (int) ($form['status_code'] ?? 301);

        RedirectValidator::assert(['locale' => $locale, 'from' => $from, 'to' => $to, 'status' => $status], $id);

        $redirect = $id !== null ? Redirect::query()->findOrFail($id) : new Redirect;
        $redirect->fill([
            'locale' => $locale,
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $status,
            'is_auto' => false,
        ]);
        $redirect->save();

        return $redirect;
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $redirect = Redirect::query()->findOrFail($id);

        return [
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
            'locale' => $redirect->locale ?? '',
            'status_code' => (string) $redirect->status_code,
        ];
    }

    private static function normalizeTarget(string $to): string
    {
        return RedirectPath::isExternal($to) ? $to : RedirectPath::normalize($to);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
