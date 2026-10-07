<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Support\Facades\File;

/**
 * A host override of the built-in form.submission html view that throws on
 * render — shared by the notifier and retrier tests (a class, not a test-file
 * function: see the note in tests/Pest.php).
 */
final class ThrowingNotificationView
{
    public static function html(string $message = 'html boom'): void
    {
        $dir = sys_get_temp_dir() . '/tbtop-cms-throwing-views-' . getmypid();
        File::ensureDirectoryExists($dir . '/notifications/form-submission');
        File::put($dir . '/notifications/form-submission/html.blade.php', "@php throw new RuntimeException('{$message}') @endphp");
        app('view')->prependNamespace('tbtop-cms', $dir);

        test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));
    }

    /**
     * Shadows a plain view with one that throws. A separate path, not an
     * overwrite of the original file: rewriting a view within the second it
     * was compiled can serve the stale compiled copy.
     */
    public static function shadow(string $view, string $message = 'boom'): void
    {
        $dir = sys_get_temp_dir() . '/tbtop-cms-shadow-views-' . getmypid();
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$view}.blade.php", "@php throw new RuntimeException('{$message}') @endphp");
        app('view')->getFinder()->prependLocation($dir);

        test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));
    }
}
