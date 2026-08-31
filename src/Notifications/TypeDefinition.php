<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use InvalidArgumentException;

/**
 * A registered notification type: what payload it carries, which templates
 * render it per format, which audiences may receive it, and which channels
 * are on by default. Built with the fluent setters below, then handed to
 * Notifications::type().
 */
final class TypeDefinition
{
    private string $label = '';

    private string $group = '';

    /** @var class-string|null */
    private ?string $payloadClass = null;

    /** @var array<value-of<Format>, string> */
    private array $views = [];

    /** @var list<Audience> */
    private array $audiences = [];

    /** @var list<string> */
    private array $defaultChannels = [];

    private function __construct(private readonly string $key) {}

    public static function make(string $key): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A notification type key must not be empty.');
        }

        return new self($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function labelValue(): string
    {
        return $this->label;
    }

    /** Settings tab this type is grouped under: 'forms', 'orders', 'inventory'. */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function groupValue(): string
    {
        return $this->group;
    }

    /** @param class-string $payloadClass readonly DTO; public props are template variables */
    public function payload(string $payloadClass): self
    {
        $this->payloadClass = $payloadClass;

        return $this;
    }

    /** @return class-string */
    public function payloadClass(): string
    {
        if ($this->payloadClass === null) {
            throw new InvalidArgumentException("Notification type [{$this->key}] has no payload class; call payload().");
        }

        return $this->payloadClass;
    }

    /** @param array<value-of<Format>, string> $views view names, by format */
    public function templates(array $views): self
    {
        $this->views = $views;

        return $this;
    }

    public function templateFor(Format $format): ?string
    {
        return $this->views[$format->value] ?? null;
    }

    /** @param list<Audience> $audiences */
    public function audiences(array $audiences): self
    {
        $this->audiences = $audiences;

        return $this;
    }

    /** @return list<Audience> */
    public function audienceValues(): array
    {
        return $this->audiences;
    }

    /** @param list<string> $channelKeys enabled by default; merchant overrides in settings */
    public function defaultChannels(array $channelKeys): self
    {
        $this->defaultChannels = $channelKeys;

        return $this;
    }

    /** @return list<string> */
    public function defaultChannelValues(): array
    {
        return $this->defaultChannels;
    }
}
