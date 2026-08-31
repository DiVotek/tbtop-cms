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

/**
 * A channel a test registers to prove the registry works for a package that
 * isn't cms itself: records every delivery it was asked to make, and throws
 * when $throwFor matches the recipient's email — see RecordingChannel::deliveries().
 */
final class RecordingChannel implements Channel
{
    /** @var list<array{message: Rendered, recipient: Recipient}> */
    private static array $deliveries = [];

    public static ?string $throwForEmail = null;

    public function key(): string
    {
        return 'recording';
    }

    public function label(): string
    {
        return 'Recording';
    }

    public function format(): Format
    {
        return Format::Text;
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
        if (self::$throwForEmail !== null && $to->email === self::$throwForEmail) {
            throw new \RuntimeException('recording channel refused ' . $to->email);
        }

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
        self::$throwForEmail = null;
    }
}
