<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\CmsAbilities;

class AuditActivityPage extends AdminPage
{
    public static function path(): string
    {
        return 'audit-log/{activity}';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return null;
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.audit');
    }

    #[\Override]
    public function title(): string
    {
        return AuditLogPage::eventLabel($this->activity()->event);
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.audit_log'), 'url' => route('tbtop.cms.audit-log-page')],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $activity = $this->activity();
        $properties = $activity->properties instanceof Collection ? $activity->properties : collect();

        return $s->stack([
            $s->section(['title' => __('tbtop-cms::audit.detail.summary'), 'variant' => 'card'], [
                $s->displayKeyValue([
                    __('tbtop-cms::audit.columns.time') => optional($activity->created_at)->format('d.m.Y H:i:s') ?? '',
                    __('tbtop-cms::audit.columns.author') => $this->causerLabel($activity),
                    __('tbtop-cms::audit.columns.event') => AuditLogPage::eventLabel($activity->event),
                    __('tbtop-cms::audit.columns.type') => AuditLogPage::typeLabel($activity->subject_type),
                    __('tbtop-cms::audit.columns.subject') => (string) $properties->get('subject_label', ''),
                ]),
            ]),
            $this->changesSection($s, __('tbtop-cms::audit.detail.before'), (array) $properties->get('old', [])),
            $this->changesSection($s, __('tbtop-cms::audit.detail.after'), (array) $properties->get('attributes', [])),
            $this->changesSection($s, __('tbtop-cms::audit.detail.context'), (array) $properties->get('context', [])),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function changesSection(S $s, string $title, array $values): Node
    {
        $formatted = [];
        foreach ($values as $key => $value) {
            $formatted[(string) $key] = self::formatValue($value);
        }

        return $s->section(['title' => $title, 'variant' => 'card'], [
            $formatted === []
                ? $s->displayText(__('tbtop-cms::audit.detail.none'))->variant('muted')
                : $s->displayKeyValue($formatted),
        ]);
    }

    private function activity(): Activity
    {
        return Activity::query()->inLog('tbtop-cms')->with('causer')->findOrFail($this->activityId());
    }

    private function activityId(): int
    {
        $id = request()->route('activity');

        return is_string($id) ? (int) $id : 0;
    }

    private function causerLabel(Activity $activity): string
    {
        $causer = $activity->causer;
        if ($causer === null) {
            return __('tbtop-cms::audit.system');
        }

        $name = (string) $causer->getAttribute('name');

        return $name !== '' ? $name : (string) $causer->getAttribute('email');
    }

    private static function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('tbtop-cms::audit.yes') : __('tbtop-cms::audit.no');
        }
        if ($value === null) {
            return '—';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
