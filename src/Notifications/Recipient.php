<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

/**
 * Who a notification is for, in whatever addresses a channel needs. A
 * recipient carries only the addresses it actually has: a telegram-only
 * admin has no email, a mail-only customer has no notifiable. Channel::supports()
 * is how a channel declares which of these it needs before send() ever runs.
 */
final readonly class Recipient
{
    public function __construct(
        public Audience $audience,
        public string $locale,
        public ?string $email = null,
        public ?string $phone = null,
        public ?object $notifiable = null,
    ) {}
}
