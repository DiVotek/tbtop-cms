<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

final readonly class Rendered
{
    /** @param array<string, mixed> $data payload values the channel may use verbatim (links, ids) */
    public function __construct(
        public string $subject,
        public string $body,
        public array $data = [],
    ) {}
}
