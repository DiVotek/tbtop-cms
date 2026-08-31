<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

final readonly class TestNotificationPayload
{
    public function __construct(public string $message) {}
}
