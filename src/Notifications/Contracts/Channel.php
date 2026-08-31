<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Contracts;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;

interface Channel
{
    /** Stable key, also the Settings toggle value: 'mail', 'telegram', 'bell', 'sms'. */
    public function key(): string;

    public function label(): string;

    /** Which format this channel renders — determines which of TypeDefinition::templates() it reads. */
    public function format(): Format;

    /**
     * Fields for NotificationSettingsPage; secret fields are encrypted by
     * Settings (tbtop-cms.settings.secret).
     *
     * @return list<Field|Node|TextBlock>
     */
    public function settings(S $s): array;

    /** Whether $to carries the address this channel needs (email, phone, notifiable). */
    public function supports(Recipient $to): bool;

    public function send(Rendered $message, Recipient $to, Settings $settings): void;
}
