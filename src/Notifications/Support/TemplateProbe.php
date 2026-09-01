<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Blade;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Tbtop\Cms\Notifications\TemplateRenderer;
use Throwable;

/**
 * Validates a merchant-edited template renders before it's saved: builds a
 * stub instance of the notification's payload class (public properties
 * filled by declared type, see stubValueFor()) and runs it through the real
 * renderer. Used by the admin editor to reject a broken template at save
 * time rather than at send time.
 */
final class TemplateProbe
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /** @param class-string $payloadClass */
    public function check(string $payloadClass, string $template, string $locale): void
    {
        try {
            $payload = $this->stubPayload($payloadClass);
        } catch (Throwable) {
            $this->checkCompilesOnly($template);

            return;
        }

        try {
            $this->renderer->render($template, $payload, $locale);
        } catch (Throwable $e) {
            throw new InvalidTemplate($e->getMessage(), previous: $e);
        }
    }

    private function checkCompilesOnly(string $template): void
    {
        try {
            Blade::compileString($template);
        } catch (Throwable $e) {
            throw new InvalidTemplate($e->getMessage(), previous: $e);
        }
    }

    /** @param class-string $payloadClass */
    private function stubPayload(string $payloadClass): object
    {
        $reflection = new ReflectionClass($payloadClass);
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);
            $property->setValue($instance, $this->stubValueFor($property));
        }

        return $instance;
    }

    private function stubValueFor(ReflectionProperty $property): mixed
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType || $type->allowsNull()) {
            return null;
        }

        $name = $type->getName();

        return match (true) {
            $name === 'string' => 'sample',
            $name === 'int' => 1,
            $name === 'float' => 1.0,
            $name === 'bool' => true,
            $name === 'array' => [],
            $type->isBuiltin() => null,
            is_a($name, DateTimeInterface::class, true) => now(),
            default => null,
        };
    }
}
