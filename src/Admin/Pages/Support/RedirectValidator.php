<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Redirect\Support\RedirectCycle;
use Tbtop\Cms\Redirect\Support\RedirectPath;

class RedirectValidator
{
    /** @param array{locale: string|null, from: string, to: string, status: int} $data */
    public static function assert(array $data, ?int $id): void
    {
        if ($data['from'] === '' || $data['to'] === '') {
            throw ValidationException::withMessages(['from_path' => __('tbtop-cms::redirects.validation.paths_required')]);
        }

        self::assertFromNotExternal($data['from']);
        self::assertToAllowed($data['to']);
        self::assertStatus($data['status']);
        self::assertUnique($data['locale'], $data['from'], $id);
        self::assertNotShadowing($data['locale'], $data['from']);
        RedirectCycle::assertAcyclic($data['locale'], $data['from'], $data['to'], $id);
    }

    private static function assertFromNotExternal(string $from): void
    {
        if (RedirectPath::isExternal($from)) {
            throw ValidationException::withMessages([
                'from_path' => __('tbtop-cms::redirects.validation.from_not_external'),
            ]);
        }
    }

    private static function assertToAllowed(string $to): void
    {
        if (! RedirectPath::isExternal($to)) {
            return;
        }

        $parts = parse_url($to);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw ValidationException::withMessages([
                'to_path' => __('tbtop-cms::redirects.validation.to_invalid_url'),
            ]);
        }

        $allowed = array_map('strtolower', config('tbtop-cms.redirects.allowed_external_hosts', []));

        if (! in_array(strtolower($host), $allowed, true)) {
            throw ValidationException::withMessages([
                'to_path' => __('tbtop-cms::redirects.validation.to_host_not_allowlisted'),
            ]);
        }
    }

    private static function assertStatus(int $status): void
    {
        if (! in_array($status, [301, 302], true)) {
            throw ValidationException::withMessages(['status_code' => __('tbtop-cms::redirects.validation.status_invalid')]);
        }
    }

    private static function assertUnique(?string $locale, string $from, ?int $id): void
    {
        $query = Redirect::query()->where('locale', $locale)->where('from_path', $from);

        if ($id !== null) {
            $query->whereKeyNot($id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['from_path' => __('tbtop-cms::redirects.validation.from_taken')]);
        }
    }

    private static function assertNotShadowing(?string $locale, string $from): void
    {
        $query = PageSlug::query()->where('path', $from);

        if ($locale !== null) {
            $query->where('locale', $locale);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['from_path' => __('tbtop-cms::redirects.validation.from_shadows_page')]);
        }
    }
}
