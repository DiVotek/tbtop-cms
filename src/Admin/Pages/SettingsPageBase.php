<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Settings\Settings;

abstract class SettingsPageBase extends Page
{
    /** @return array<string, string> */
    abstract protected function map(): array;

    /** @return list<Field|Node|TextBlock> */
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
            ->record($this->presentRecord(SettingsForm::record($this->map(), app(Settings::class), $this->defaults())))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    /**
     * Values a never-stored key shows in the form — the same fallback its
     * consumer applies at runtime, so the select is not blank on a fresh
     * install. Not written to the store until the page is saved.
     *
     * @return array<string, mixed> field name => value
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Hook for pages whose field widgets need a different shape than the
     * stored value (e.g. a tags field editing a newline-joined string).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function presentRecord(array $record): array
    {
        return $record;
    }

    /**
     * Inverse of presentRecord() — maps submitted widget values back to the
     * stored shape before they hit the settings store.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function normalizeForm(array $form): array
    {
        return $form;
    }

    /**
     * Runs before SettingsForm::apply(); throw ValidationException to reject
     * the submission (see SeoSettingsPage's guard* methods for the same
     * mechanism from normalizeForm() — this hook exists for checks that need
     * to run after normalizeForm() but before anything is persisted).
     *
     * @param  array<string, mixed>  $form
     */
    protected function validateForm(array $form): void {}

    /**
     * Runs after SettingsForm::apply() has persisted the mapped settings —
     * for side effects on data this page owns outside map() (e.g. template
     * rows keyed by fields that are deliberately not settings).
     *
     * @param  array<string, mixed>  $form
     */
    protected function afterSave(array $form): void {}

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::settings.common.save'))
            ->color('primary')->keybinding('mod+s')->submit();
    }

    private function save(ActionCtx $ctx): Effects
    {
        $form = $this->normalizeForm($ctx->form);
        $this->validateForm($form);

        SettingsForm::apply($form, $this->map(), app(Settings::class));
        $this->afterSave($form);

        return Effects::make()->notify(__('tbtop-cms::settings.common.saved'));
    }

    private function selfUrl(): string
    {
        // static::slug() is Str::kebab(class_basename) and already ends in
        // "-page" for these classes (e.g. SiteSettingsPage → site-settings-page).
        return route('tbtop.cms.' . static::slug());
    }
}
