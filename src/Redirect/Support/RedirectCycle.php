<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Redirect\Models\Redirect;

class RedirectCycle
{
    private const int MAX_HOPS = 20;

    public static function assertAcyclic(?string $locale, string $from, string $to, ?int $id): void
    {
        if (RedirectPath::isExternal($to)) {
            return;
        }

        $locales = $locale !== null
            ? [$locale]
            : [null, ...Redirect::query()->whereNotNull('locale')->distinct()->pluck('locale')->all()];

        foreach ($locales as $candidate) {
            self::assertAcyclicForLocale($candidate, $from, $to, $id);
        }
    }

    private static function assertAcyclicForLocale(?string $locale, string $from, string $to, ?int $id): void
    {
        $seen = [$from];
        $current = RedirectPath::normalize($to);

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (in_array($current, $seen, true)) {
                throw ValidationException::withMessages(['to_path' => 'This redirect would create a loop.']);
            }

            $seen[] = $current;
            $next = self::nextTarget($locale, $current, $id);

            if ($next === null) {
                return;
            }

            $current = $next;
        }

        throw ValidationException::withMessages(['to_path' => 'Redirect chain is too long.']);
    }

    private static function nextTarget(?string $locale, string $path, ?int $id): ?string
    {
        $specific = self::lookup($path, $id)->where('locale', $locale)->first();
        $redirect = $specific ?? self::lookup($path, $id)->whereNull('locale')->first();

        if (! $redirect instanceof Redirect || RedirectPath::isExternal($redirect->to_path)) {
            return null;
        }

        return RedirectPath::normalize($redirect->to_path);
    }

    /** @return Builder<Redirect> */
    private static function lookup(string $path, ?int $id): Builder
    {
        $query = Redirect::query()->where('from_path', $path);

        if ($id !== null) {
            $query->whereKeyNot($id);
        }

        return $query;
    }
}
