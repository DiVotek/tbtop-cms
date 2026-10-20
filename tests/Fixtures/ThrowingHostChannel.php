<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use RuntimeException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;

/** A host channel the package ships no translations for; its send always fails. */
final class ThrowingHostChannel implements Channel
{
    public function key(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'SMS';
    }

    public function format(): Format
    {
        return Format::Short;
    }

    /** @return list<Field> */
    public function settings(S $s): array
    {
        return [];
    }

    public function supports(Recipient $to): bool
    {
        return $to->notifiable !== null;
    }

    public function send(Rendered $message, Recipient $to, Settings $settings): void
    {
        throw new RuntimeException('gateway down');
    }
}
