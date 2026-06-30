<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Redirect\Support\RedirectCycle;

class RedirectValidator
{
    /** @param array{locale: string|null, from: string, to: string, status: int} $data */
    public static function assert(array $data, ?int $id): void
    {
        if ($data['from'] === '' || $data['to'] === '') {
            throw ValidationException::withMessages(['from_path' => 'From and to paths are required.']);
        }

        self::assertStatus($data['status']);
        self::assertUnique($data['locale'], $data['from'], $id);
        self::assertNotShadowing($data['locale'], $data['from']);
        RedirectCycle::assertAcyclic($data['locale'], $data['from'], $data['to'], $id);
    }

    private static function assertStatus(int $status): void
    {
        if (! in_array($status, [301, 302], true)) {
            throw ValidationException::withMessages(['status_code' => 'Status code must be 301 or 302.']);
        }
    }

    private static function assertUnique(?string $locale, string $from, ?int $id): void
    {
        $query = Redirect::query()->where('locale', $locale)->where('from_path', $from);

        if ($id !== null) {
            $query->whereKeyNot($id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['from_path' => 'A redirect already exists for this path and locale.']);
        }
    }

    private static function assertNotShadowing(?string $locale, string $from): void
    {
        $query = PageSlug::query()->where('path', $from);

        if ($locale !== null) {
            $query->where('locale', $locale);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['from_path' => 'A page already exists at this path.']);
        }
    }
}
