<?php

declare(strict_types=1);

namespace Tbtop\Cms\Language\Observers;

use DomainException;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;

class LanguageObserver
{
    public function saving(Language $language): void
    {
        $this->guardLastDefaultNotUnset($language);

        if ($language->is_default) {
            $language->is_admin_active = true;
            $language->is_frontend_active = true;

            return;
        }

        $this->guardFrontendRequiresAdmin($language);

        if (! $language->is_admin_active) {
            $language->is_frontend_active = false;
        }
    }

    public function deleting(Language $language): void
    {
        if ($language->is_default) {
            throw new DomainException(
                "Default language '{$language->locale}' cannot be deleted. Reassign default first."
            );
        }
    }

    public function saved(Language $language): void
    {
        if ($language->is_default) {
            DB::transaction(function () use ($language): void {
                Language::query()
                    ->where('is_default', true)
                    ->whereKeyNot($language->getKey())
                    ->update(['is_default' => false]);

                Language::query()
                    ->whereKey($language->getKey())
                    ->update(['is_default' => true]);
            });
        }

        // Restoring also fires saved; restored handles that lifecycle once.
        if (! $language->wasChanged('deleted_at')) {
            $this->languagesChanged();
        }
    }

    public function deleted(Language $language): void
    {
        $this->languagesChanged();
    }

    public function restored(Language $language): void
    {
        $this->languagesChanged();
    }

    private function guardFrontendRequiresAdmin(Language $language): void
    {
        if (! $language->exists) {
            if ($language->is_frontend_active && ! $language->is_admin_active) {
                throw new DomainException(
                    "Language '{$language->locale}' cannot be frontend-active without being admin-active."
                );
            }

            return;
        }

        // Admin turned off cascades frontend off; turning frontend on while admin stays off is rejected.
        $adminFlippedOff = $language->isDirty('is_admin_active') && ! $language->is_admin_active;
        $frontendTurnedOn = $language->isDirty('is_frontend_active') && $language->is_frontend_active;

        if ($frontendTurnedOn && ! $language->is_admin_active && ! $adminFlippedOff) {
            throw new DomainException(
                "Language '{$language->locale}' cannot be frontend-active without being admin-active."
            );
        }
    }

    private function guardLastDefaultNotUnset(Language $language): void
    {
        if (! $language->exists || ! $language->isDirty('is_default') || $language->is_default) {
            return;
        }

        $anotherDefaultExists = Language::query()
            ->where('is_default', true)
            ->whereKeyNot($language->getKey())
            ->exists();

        if (! $anotherDefaultExists) {
            throw new DomainException(
                "Language '{$language->locale}' is the only default and cannot be unset. Assign another default first."
            );
        }
    }

    private function languagesChanged(): void
    {
        if (app()->bound(Languages::class)) {
            app(Languages::class)->refresh();
        }

        event(new LanguagesChanged);
    }
}
