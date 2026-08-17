<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks\Concerns;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * File-inspecting checks must not let an unreadable host file escalate
 * through HealthCheckRunner's generic "check crashed" error path: an
 * exists-but-unreadable file (permissions, or a directory squatting on the
 * expected path) is a property of the host being diagnosed, and each check
 * owns the severity and wording of that finding.
 */
trait ReadsFiles
{
    private function readFile(string $path): ?string
    {
        try {
            return File::get($path);
        } catch (Throwable) {
            return null;
        }
    }
}
