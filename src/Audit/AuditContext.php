<?php

declare(strict_types=1);

namespace Tbtop\Cms\Audit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

final class AuditContext
{
    /** @var array{event: string, context: array<string, mixed>}|null */
    private ?array $override = null;

    /**
     * spatie v5 dropped LogBatch/batch_uuid (see UPGRADING.md). This holds
     * the replacement: a package-level batch id stored in properties.batch,
     * open for the lifetime of the outermost batch() call — a nested call
     * reuses it rather than opening a second one.
     */
    private ?string $batchUuid = null;

    /** @param array<string, mixed> $context */
    public function during(string $event, array $context, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = ['event' => $event, 'context' => $context];

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function batch(Closure $callback): mixed
    {
        if ($this->batchUuid !== null) {
            return $callback();
        }

        $this->batchUuid = (string) Str::uuid();

        try {
            return $callback();
        } finally {
            $this->batchUuid = null;
        }
    }

    /**
     * @param  list<string>  $markerFields
     * @param  list<string>  $blockFields
     */
    public function enrich(
        ActivityContract $activity,
        string $defaultEvent,
        string $subjectLabel,
        array $markerFields = [],
        array $blockFields = [],
    ): void {
        if (! $activity instanceof Model) {
            throw new LogicException('The activity log model must extend Eloquent Model.');
        }

        // changed_fields is captured before markerFields are stripped: a
        // redacted field's NAME must survive, only its value is dropped.
        // Tracked model changes live in attribute_changes since spatie v5
        // (properties now holds only our own custom keys).
        $event = $this->override['event'] ?? $defaultEvent;
        $activityChanges = $activity->getAttribute('attribute_changes');
        $changes = $activityChanges instanceof Collection
            ? $activityChanges->toArray()
            : (array) $activityChanges;
        $existingProperties = $activity->getAttribute('properties');
        $properties = $existingProperties instanceof Collection
            ? $existingProperties->toArray()
            : (array) $existingProperties;
        $changedFields = array_values(array_unique([
            ...array_keys((array) ($changes['attributes'] ?? [])),
            ...array_keys((array) ($changes['old'] ?? [])),
        ]));

        foreach ($markerFields as $field) {
            unset($changes['attributes'][$field], $changes['old'][$field]);
        }

        $context = [];

        foreach ($blockFields as $field) {
            $new = $changes['attributes'][$field] ?? null;
            $old = $changes['old'][$field] ?? null;

            if (array_key_exists($field, (array) ($changes['attributes'] ?? []))) {
                $changes['attributes'][$field] = self::blockSummary($new);
            }
            if (array_key_exists($field, (array) ($changes['old'] ?? []))) {
                $changes['old'][$field] = self::blockSummary($old);
            }
            if (is_array($new) && is_array($old)) {
                $context[$field . '_order_changed'] = self::blockIds($new) !== self::blockIds($old);
            }
        }

        $properties['subject_label'] = $subjectLabel;
        $properties['changed_fields'] = $changedFields;
        $properties['context'] = [
            ...(array) ($properties['context'] ?? []),
            ...$context,
            ...($this->override['context'] ?? []),
        ];
        if ($this->batchUuid !== null) {
            $properties['batch'] = $this->batchUuid;
        }

        $activity->setAttribute('event', $event);
        $activity->setAttribute('description', 'tbtop-cms.audit.events.' . $event);
        $activity->setAttribute('attribute_changes', collect($changes));
        $activity->setAttribute('properties', collect($properties));

        $causer = CurrentAdmin::canAccess() ? CurrentAdmin::user() : null;
        if ($causer !== null) {
            $activity->causer()->associate($causer);
        } else {
            $activity->setAttribute('causer_id', null);
            $activity->setAttribute('causer_type', null);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function log(
        Model $subject,
        string $event,
        string $subjectLabel,
        array $changes = [],
        array $context = [],
        bool $allowAnonymous = false,
    ): void {
        $causer = CurrentAdmin::canAccess() ? CurrentAdmin::user() : null;
        if ($causer === null && ! $allowAnonymous) {
            return;
        }

        $logger = activity('tbtop-cms')
            ->performedOn($subject)
            ->event($event)
            ->withChanges($changes)
            ->withProperties([
                'subject_label' => $subjectLabel,
                'changed_fields' => array_values(array_unique([
                    ...array_keys((array) ($changes['attributes'] ?? [])),
                    ...array_keys((array) ($changes['old'] ?? [])),
                ])),
                'context' => $context,
                ...($this->batchUuid !== null ? ['batch' => $this->batchUuid] : []),
            ]);

        if ($causer !== null) {
            $logger->causedBy($causer);
        } else {
            $logger->causedByAnonymous();
        }

        $logger->log('tbtop-cms.audit.events.' . $event);
    }

    /** @return array{count: int, kinds: list<string>} */
    private static function blockSummary(mixed $content): array
    {
        $blocks = is_array($content) ? array_filter($content, 'is_array') : [];
        $kinds = array_values(array_unique(array_filter(array_map(
            static fn (array $block): string => (string) ($block['kind'] ?? ''),
            $blocks,
        ))));

        return ['count' => count($blocks), 'kinds' => $kinds];
    }

    /**
     * @param  array<int, mixed>  $content
     * @return list<string>
     */
    private static function blockIds(array $content): array
    {
        return array_values(array_map(
            static fn (mixed $block): string => is_array($block) ? (string) ($block['id'] ?? '') : '',
            $content,
        ));
    }
}
