<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Errors;

use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Models\Page;

/**
 * Looks an error page up by its class column, never by path — error pages
 * carry no PageSlug row (see PageSlugWriter::sync()).
 */
class ErrorPageResolver
{
    public function forStatus(int $status): ?Page
    {
        $errorClass = ErrorClass::fromStatus($status);

        if ($errorClass === null) {
            return null;
        }

        return Page::query()->where('error_class', $errorClass)->live()->first();
    }
}
