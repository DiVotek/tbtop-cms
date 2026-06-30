<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Settings\Settings;

abstract class SettingsPageBase extends Page
{
    /** @return array<string, string> */
    abstract protected function map(): array;

    /** @return list<Field> */
    abstract protected function fields(S $s): array;

    public function view(S $s): Node
    {
        $form = $s->form(static::path(), [
            ...$this->fields($s),
            $s->actionsRow([
                $s->action('save')->label('Save')->color('primary')->keybinding('mod+s')->submit(),
            ]),
        ])
            ->record(SettingsForm::record($this->map(), app(Settings::class)))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function save(ActionCtx $ctx): Effects
    {
        SettingsForm::apply($ctx->form, $this->map(), app(Settings::class));

        return Effects::make()->notify('Saved');
    }
}
