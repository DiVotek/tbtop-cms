<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
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
            $s->text('from_path')->label(__('tbtop-cms::redirects.fields.from_path'))->required()
                ->helperText(__('tbtop-cms::redirects.helpers.from_path')),
            $s->text('to_path')->label(__('tbtop-cms::redirects.fields.to_path'))->required()
                ->helperText(__('tbtop-cms::redirects.helpers.to_path')),
            // Same prefill as the 404 log's create-redirect modal and the
            // fallback in save().
            $s->select('locale')->label(__('tbtop-cms::redirects.fields.locale'))->options(RedirectFormOptions::locales())
                ->default(RedirectFormOptions::ALL_LOCALES),
            $s->select('status_code')->label(__('tbtop-cms::redirects.fields.status_code'))->required()
                ->options(RedirectFormOptions::statuses())->default('301'),
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

        try {
            $redirect->save();
        } catch (QueryException $e) {
            $duplicate = Redirect::query()
                ->where('locale', $locale)
                ->where('from_path', $from)
                ->when($id !== null, static fn ($query) => $query->whereKeyNot($id))
                ->exists();

            if (! $duplicate) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'from_path' => __('tbtop-cms::redirects.validation.from_taken'),
            ]);
        }

        return $redirect;
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $redirect = Redirect::query()->findOrFail($id);

        return [
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
            'locale' => $redirect->locale ?? RedirectFormOptions::ALL_LOCALES,
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

        return $value === '' || $value === RedirectFormOptions::ALL_LOCALES ? null : $value;
    }
}
