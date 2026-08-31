<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;

/** Second test channel, on a different format, so a test can prove one recipient reaches multiple channels. */
final class RecordingShortChannel implements Channel
{
    /** @var list<array{message: Rendered, recipient: Recipient}> */
    private static array $deliveries = [];

    public function key(): string
    {
        return 'recording-short';
    }

    public function label(): string
    {
        return 'Recording Short';
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
        return $to->email !== null;
    }

    public function send(Rendered $message, Recipient $to, Settings $settings): void
    {
        self::$deliveries[] = ['message' => $message, 'recipient' => $to];
    }

    /** @return list<array{message: Rendered, recipient: Recipient}> */
    public static function deliveries(): array
    {
        return self::$deliveries;
    }

    public static function reset(): void
    {
        self::$deliveries = [];
    }
}
