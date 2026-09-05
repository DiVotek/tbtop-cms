<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Support;

use Spatie\Activitylog\Support\ActivityLogger;
use Tbtop\Cms\Redirect\Models\Redirect;

class RedirectWriter
{
    public function recordMove(string $locale, string $from, string $to): void
    {
        $from = RedirectPath::normalize($from);
        $to = RedirectPath::normalize($to);

        if ($from === '' || $from === $to) {
            return;
        }

        app(ActivityLogger::class)->withoutLogging(function () use ($locale, $from, $to): void {
            Redirect::query()
                ->where('locale', $locale)
                ->where('is_auto', true)
                ->where('to_path', $from)
                ->update(['to_path' => $to]);

            Redirect::query()->updateOrCreate(
                ['locale' => $locale, 'from_path' => $from],
                ['to_path' => $to, 'status_code' => 301, 'is_auto' => true],
            );
        });
    }

    public function clearAt(string $locale, string $path): void
    {
        Redirect::query()
            ->where('locale', $locale)
            ->where('from_path', RedirectPath::normalize($path))
            ->delete();
    }
}
