<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Support;

use RuntimeException;

/** Thrown by TemplateProbe when a merchant-edited template fails to render against a sample payload. */
final class InvalidTemplate extends RuntimeException {}
