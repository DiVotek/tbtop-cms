<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\View;
use ReflectionClass;
use ReflectionProperty;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\NotificationRegistry;
use Tbtop\Cms\Notifications\TypeDefinition;

/**
 * Field-name conventions and lookups shared by NotificationSettingsPage's
 * channel toggles and template editor. Field names are always derived from
 * the registry, never parsed back — the server-side map built by fieldMap()
 * is how a posted `tpl__*` key is turned back into (type, format, locale,
 * part).
 */
final class NotificationTemplateFields
{
    /** Registry type key -> field-name slug ("form.submission" -> "form__submission"). */
    public static function slug(string $typeKey): string
    {
        return str_replace('.', '__', $typeKey);
    }

    public static function channelsField(string $typeKey): string
    {
        return 'channels__' . self::slug($typeKey);
    }

    public static function subjectField(string $typeKey, Format $format, string $locale): string
    {
        return 'tpl__' . self::slug($typeKey) . '__' . $format->value . '__' . $locale . '__subject';
    }

    public static function bodyField(string $typeKey, Format $format, string $locale): string
    {
        return 'tpl__' . self::slug($typeKey) . '__' . $format->value . '__' . $locale . '__body';
    }

    public static function resetActionName(string $typeKey, Format $format): string
    {
        return 'resetTemplate__' . self::slug($typeKey) . '__' . $format->value;
    }

    /**
     * Types grouped by TypeDefinition::groupValue(), in first-seen group
     * order (registry order within each group).
     *
     * @return array<string, list<TypeDefinition>>
     */
    public static function groupedTypes(NotificationRegistry $registry): array
    {
        $groups = [];

        foreach ($registry->types() as $type) {
            $groups[$type->groupValue()][] = $type;
        }

        return $groups;
    }

    /**
     * Channels a type's checkbox list may offer: every registered channel
     * whose format the type has a built-in or override-able template for,
     * in registry order.
     *
     * @return list<Channel>
     */
    public static function channelsFor(TypeDefinition $type, NotificationRegistry $registry): array
    {
        return array_values(array_filter(
            $registry->channels(),
            static fn (Channel $channel): bool => $type->templateFor($channel->format()) !== null,
        ));
    }

    /** @return list<Format> */
    public static function formatsFor(TypeDefinition $type): array
    {
        return array_values(array_filter(
            Format::cases(),
            static fn (Format $format): bool => $type->templateFor($format) !== null,
        ));
    }

    /**
     * Server-side field-name -> (type, format, locale, part) map, rebuilt
     * fresh every request from the registry and the active admin locales —
     * never parsed back out of a field name.
     *
     * @param  list<string>  $locales
     * @return array<string, array{0: string, 1: Format, 2: string, 3: 'subject'|'body'}>
     */
    public static function fieldMap(NotificationRegistry $registry, array $locales): array
    {
        $map = [];

        foreach ($registry->types() as $type) {
            foreach (self::formatsFor($type) as $format) {
                foreach ($locales as $locale) {
                    $map[self::subjectField($type->key(), $format, $locale)] = [$type->key(), $format, $locale, 'subject'];
                    $map[self::bodyField($type->key(), $format, $locale)] = [$type->key(), $format, $locale, 'body'];
                }
            }
        }

        return $map;
    }

    /** The built-in view's Blade source, or '' when the view cannot be resolved. */
    public static function builtInSource(TypeDefinition $type, Format $format): string
    {
        $view = $type->templateFor($format);

        if ($view === null || ! View::exists($view)) {
            return '';
        }

        try {
            $path = View::getFinder()->find($view);
        } catch (\InvalidArgumentException) {
            return '';
        }

        return file_get_contents($path) ?: '';
    }

    /** Public property names of the payload class, as "{{ $name }}" template variables. */
    public static function payloadVariables(TypeDefinition $type): string
    {
        $reflection = new ReflectionClass($type->payloadClass());
        $names = array_map(
            static fn (ReflectionProperty $property): string => '{{ $' . $property->getName() . ' }}',
            array_values(array_filter(
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn (ReflectionProperty $property): bool => ! $property->isStatic(),
            )),
        );

        return implode(', ', $names);
    }
}
