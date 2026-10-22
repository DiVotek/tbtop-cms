<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Pages\Support\NotificationTemplateFields as Fields;
use Tbtop\Cms\Admin\Support\AdminLocales;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;
use Tbtop\Cms\Notifications\TypeDefinition;

/**
 * The owner-only template editors on the notification settings page: per
 * type and format, a subject/body pair for each admin UI locale and a reset
 * action that drops the stored overrides. Field names come from
 * NotificationTemplateFields.
 */
final class NotificationTemplateEditors
{
    /** @return list<Node> */
    public static function for(S $s, TypeDefinition $type): array
    {
        $locales = self::locales();

        return array_map(
            static fn (Format $format): Node => self::formatEditor($s, $type, $format, $locales),
            Fields::formatsFor($type),
        );
    }

    /** @param  list<string>  $locales */
    private static function formatEditor(S $s, TypeDefinition $type, Format $format, array $locales): Node
    {
        $fields = [];
        foreach ($locales as $locale) {
            array_push($fields, ...self::localeTemplateFields($s, $type, $format, $locale));
        }

        $fields[] = $s->actionsRow([self::resetTemplateAction($s, $type, $format)]);

        return $s->collapsible([
            'label' => __("tbtop-cms::settings.notifications.formats.{$format->value}"),
            'collapsed' => true,
        ], $fields);
    }

    /** @return list<Field> */
    private static function localeTemplateFields(S $s, TypeDefinition $type, Format $format, string $locale): array
    {
        $placeholder = Fields::builtInSource($type, $format);
        $helperText = __('tbtop-cms::settings.notifications.helpers.template', [
            'variables' => Fields::payloadVariables($type),
        ]);

        return [
            $s->text(Fields::subjectField($type->key(), $format, $locale))
                ->label(__('tbtop-cms::settings.notifications.fields.subject') . " ({$locale})")
                ->rules('nullable|max:255'),
            $s->textarea(Fields::bodyField($type->key(), $format, $locale))
                ->label(__('tbtop-cms::settings.notifications.fields.body') . " ({$locale})")
                ->placeholder($placeholder)
                ->helperText($helperText)
                ->rules('nullable|string'),
        ];
    }

    private static function resetTemplateAction(S $s, TypeDefinition $type, Format $format): ActionBuilder
    {
        return $s->action(Fields::resetActionName($type->key(), $format))
            ->label(__('tbtop-cms::settings.notifications.actions.reset_template'))
            ->confirm(__('tbtop-cms::settings.notifications.actions.reset_template_confirm'))
            ->handle(static fn (ActionCtx $ctx): Effects => self::resetTemplate($type, $format));
    }

    private static function resetTemplate(TypeDefinition $type, Format $format): Effects
    {
        if (! CurrentAdmin::isOwner()) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.errors.owner_only'), 'error');
        }

        $locales = self::locales();

        NotificationTemplate::query()
            ->where('type', $type->key())
            ->where('format', $format->value)
            ->delete();

        $cleared = [];
        foreach ($locales as $locale) {
            $cleared[Fields::subjectField($type->key(), $format, $locale)] = '';
            $cleared[Fields::bodyField($type->key(), $format, $locale)] = '';
        }

        return Effects::make()
            ->notify(__('tbtop-cms::settings.notifications.actions.template_reset'))
            ->setFormData($cleared);
    }

    /**
     * Admin UI locales to offer a template editor for: staff notifications
     * render in the reader's admin locale, never a page's content locale.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return AdminLocales::all();
    }
}
