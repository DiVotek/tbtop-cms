<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\BuiltIn;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Frontend\Seo\SiteIdentity;

/**
 * Built in: where the business is. The first published office is the main
 * one: its address, phones, emails, opening hours and coordinates are the
 * identity node's (SiteIdentity) and SiteContacts::phones()/emails(); the
 * others are published as the identity's `department`s.
 */
class Offices extends Collection
{
    public const string KEY = 'offices';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tbtop-cms::collections.offices.label');
    }

    #[\Override]
    public function singularLabel(): string
    {
        return __('tbtop-cms::collections.offices.singular');
    }

    #[\Override]
    public function icon(): string
    {
        return 'map-pin';
    }

    #[\Override]
    public function navigationGroup(): string
    {
        return 'settings';
    }

    #[\Override]
    public function navigationOrder(): int
    {
        return 21;
    }

    public function fields(S $s): array
    {
        $t = static fn (string $key): string => __('tbtop-cms::collections.offices.fields.' . $key);
        $days = array_map(
            static fn (string $day): array => ['value' => $day, 'label' => __('tbtop-cms::settings.seo.days.' . strtolower($day))],
            SiteIdentity::DAYS,
        );

        return [
            $s->text('name')->label($t('name'))->translatable()->required()->rules('max:255'),
            $s->text('street')->label($t('street'))->translatable()->rules('nullable|max:255'),
            $s->text('locality')->label($t('locality'))->translatable()->rules('nullable|max:255'),
            $s->text('region')->label($t('region'))->translatable()->rules('nullable|max:255'),
            $s->text('postal_code')->label($t('postal_code'))->rules('nullable|string|max:20'),
            $s->text('country')->label($t('country'))->placeholder('UA')->rules(['nullable', 'regex:/^[A-Za-z]{2}$/']),
            $s->repeater('phones')->label($t('phones'))->fields([
                $s->text('number')->label($t('number'))->placeholder('+380 44 000 00 00')->required()->rules('max:40'),
            ]),
            $s->repeater('emails')->label($t('emails'))->fields([
                $s->text('address')->label($t('address'))->required()->rules('email|max:190'),
            ]),
            $s->repeater('opening_hours')->label($t('opening_hours'))->fields([
                $s->select('days')->label($t('days'))->multiple()->options($days)
                    ->rules('required|in:' . implode(',', SiteIdentity::DAYS)),
                $s->time('opens')->label($t('opens'))->rules('required|date_format:H:i'),
                $s->time('closes')->label($t('closes'))->rules('required|date_format:H:i'),
            ]),
            $s->number('latitude')->label($t('latitude'))->step('any')->rules('nullable|numeric|between:-90,90'),
            $s->number('longitude')->label($t('longitude'))->step('any')->rules('nullable|numeric|between:-180,180'),
        ];
    }
}
