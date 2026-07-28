<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Settings\Settings;

abstract class SettingsPageBase extends Page
{
    /** @return array<string, string> */
    abstract protected function map(): array;

    /** @return list<Field|Node> */
    abstract protected function fields(S $s): array;

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.settings');
    }

    public function view(S $s): Node
    {
        // No settings list to cancel back to, unlike other EditFooter
        // callers — cancel reloads this same singleton settings page.
        $footer = EditFooter::build($s, $this->selfUrl(), $this->saveAction($s));

        $form = $s->form(static::path(), [
            ...$this->fields($s),
            $footer,
        ])
            ->record(SettingsForm::record($this->map(), app(Settings::class)))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::settings.common.save'))
            ->color('primary')->keybinding('mod+s')->submit();
    }

    private function save(ActionCtx $ctx): Effects
    {
        SettingsForm::apply($ctx->form, $this->map(), app(Settings::class));

        return Effects::make()->notify(__('tbtop-cms::settings.common.saved'));
    }

    private function selfUrl(): string
    {
        // static::slug() is Str::kebab(class_basename) and already ends in
        // "-page" for these classes (e.g. SiteSettingsPage → site-settings-page).
        return route('tbtop.cms.' . static::slug());
    }
}
