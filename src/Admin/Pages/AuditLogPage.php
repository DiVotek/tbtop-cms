<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;

class AuditLogPage extends AdminPage
{
    public static function path(): string
    {
        return 'audit-log';
    }

    /** @return array<string, mixed> */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.audit_log'),
            'order' => 40,
            'icon' => 'history',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.audit');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::audit.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::audit.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.audit_log')],
        ];
    }

    public function view(S $s): Node
    {
        $table = $s->table('auditActivities')
            ->query($this->query(...))
            ->columns([
                Column::make('created_at')->label(__('tbtop-cms::audit.columns.time'))->datetime()->sortable(),
                Column::make('causer.name')->label(__('tbtop-cms::audit.columns.author'))->muted(),
                Column::make('event')->label(__('tbtop-cms::audit.columns.event'))
                    ->formatUsing(self::eventLabel(...)),
                Column::make('subject_type')->label(__('tbtop-cms::audit.columns.type'))
                    ->formatUsing(self::typeLabel(...)),
                Column::make('properties.subject_label')->label(__('tbtop-cms::audit.columns.subject'))->emphasized(),
                Column::make('properties.changed_fields')->label(__('tbtop-cms::audit.columns.changes'))
                    ->formatUsing(static fn (mixed $value): string => is_array($value) ? implode(', ', $value) : ''),
            ])
            ->filters($this->filters($s))
            ->filtersFormColumns(2)
            ->defaultSort('created_at', 'desc')
            ->paginate(25)
            ->recordUrl(static fn (Activity $activity): string => route('tbtop.cms.audit-activity-page', ['activity' => $activity->id]))
            ->emptyState(
                __('tbtop-cms::audit.empty.title'),
                __('tbtop-cms::audit.empty.description'),
                'history',
            );

        return $s->stack([$table]);
    }

    /** @return Builder<Activity> */
    private function query(): Builder
    {
        return Activity::query()->inLog('tbtop-cms')->with('causer');
    }

    /** @return list<Field> */
    private function filters(S $s): array
    {
        return [
            $s->select('subject_type')->label(__('tbtop-cms::audit.filters.type'))->options(self::typeOptions()),
            $s->text('subject_id')->label(__('tbtop-cms::audit.filters.subject_id')),
            $s->select('event')->label(__('tbtop-cms::audit.filters.event'))->options(self::eventOptions()),
            $s->select('causer_id')->label(__('tbtop-cms::audit.filters.author'))->options($this->authorOptions()),
            $s->daterange('created_at')->label(__('tbtop-cms::audit.filters.period')),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function typeOptions(): array
    {
        return array_map(
            static fn (string $type): array => ['value' => $type, 'label' => self::typeLabel($type)],
            array_keys(self::types()),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private static function eventOptions(): array
    {
        return array_map(
            static fn (string $event): array => ['value' => $event, 'label' => self::eventLabel($event)],
            array_keys(__('tbtop-cms::audit.events')),
        );
    }

    /** @return list<array{value: int|string, label: string}> */
    private function authorOptions(): array
    {
        $ids = CmsUserProfile::query()->pluck('user_id')->merge(
            Activity::query()->inLog('tbtop-cms')->whereNotNull('causer_id')->distinct()->pluck('causer_id'),
        );

        return AuthorNames::lookup($ids)->map(
            static fn (Model $user): array => [
                'value' => $user->getKey(),
                'label' => AuthorNames::label($user->getKey(), collect([$user->getKey() => $user])) ?? (string) $user->getKey(),
            ],
        )->values()->all();
    }

    public static function eventLabel(mixed $event): string
    {
        $key = 'tbtop-cms::audit.events.' . (string) $event;
        $label = __($key);

        return $label === $key ? (string) $event : $label;
    }

    public static function typeLabel(mixed $type): string
    {
        return self::types()[(string) $type] ?? class_basename((string) $type);
    }

    /** @return array<class-string<Model>, string> */
    private static function types(): array
    {
        /** @var class-string<Model> $user */
        $user = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return [
            Page::class => __('tbtop-cms::audit.types.page'),
            Redirect::class => __('tbtop-cms::audit.types.redirect'),
            Language::class => __('tbtop-cms::audit.types.language'),
            Layout::class => __('tbtop-cms::audit.types.layout'),
            SharedBlock::class => __('tbtop-cms::audit.types.shared_block'),
            Setting::class => __('tbtop-cms::audit.types.setting'),
            FormSubmission::class => __('tbtop-cms::audit.types.submission'),
            NotFoundHit::class => __('tbtop-cms::audit.types.not_found'),
            $user => __('tbtop-cms::audit.types.user'),
        ];
    }
}
