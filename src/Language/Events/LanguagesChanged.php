<?php

declare(strict_types=1);

namespace Tbtop\Cms\Language\Events;

/**
 * Raised whenever the set of configured languages changes — created,
 * edited, soft-deleted, restored, or force-deleted. A typical subscriber
 * invalidates a cached locale list or navigation payload.
 */
final class LanguagesChanged
{
    public function __construct() {}
}
