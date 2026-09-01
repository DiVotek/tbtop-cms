<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use InvalidArgumentException;
use Tbtop\Cms\Notifications\Contracts\Channel;

/**
 * Holds every registered type and channel. Registration
 * (Notifications::type()/channel()) is collected eagerly as providers call
 * it; resolution to Channel instances is lazy so a channel's own
 * constructor dependencies are resolved through the container only once
 * something actually needs it.
 */
class NotificationRegistry
{
    /** @var array<string, TypeDefinition> */
    private array $types = [];

    /** @var array<string, class-string<Channel>> */
    private array $channelClasses = [];

    /** @var array<string, Channel> */
    private array $channels = [];

    public function registerType(TypeDefinition $type): void
    {
        $key = $type->key();

        if (isset($this->types[$key])) {
            throw new InvalidArgumentException("Notification type [{$key}] is already registered.");
        }

        $this->types[$key] = $type;
    }

    public function type(string $key): TypeDefinition
    {
        if (! isset($this->types[$key])) {
            throw new InvalidArgumentException("Notification type [{$key}] is not registered.");
        }

        return $this->types[$key];
    }

    public function hasType(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return array<string, TypeDefinition> in registration order */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * $class is documented as plain class-string, not class-string<Channel>
     * — Notifications::channel()'s own signature already promises the
     * narrower type to its callers, but that's a static PHPDoc hint with no
     * runtime enforcement, so is_a() below is this registry's actual guard
     * against a caller passing an unrelated class.
     *
     * @param  class-string  $class
     */
    public function registerChannel(string $class): void
    {
        if (! is_a($class, Channel::class, true)) {
            throw new InvalidArgumentException('Channel [' . $class . '] must implement ' . Channel::class . '.');
        }

        /** @var Channel $instance */
        $instance = app($class);
        $key = $instance->key();

        if ($key === '') {
            throw new InvalidArgumentException("Channel [{$class}] must return a non-empty key.");
        }

        if (isset($this->channelClasses[$key])) {
            throw new InvalidArgumentException("Channel [{$key}] is already registered.");
        }

        $this->channelClasses[$key] = $class;
        $this->channels[$key] = $instance;
    }

    public function channel(string $key): Channel
    {
        if (! isset($this->channels[$key])) {
            throw new InvalidArgumentException("Channel [{$key}] is not registered.");
        }

        return $this->channels[$key];
    }

    public function hasChannel(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    /** @return array<string, Channel> in registration order */
    public function channels(): array
    {
        return $this->channels;
    }
}
