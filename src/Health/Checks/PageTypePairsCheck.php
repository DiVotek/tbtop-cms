<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageTypePairProblem;

/**
 * Pages stored before PageObserver enforced the pair rule, written past
 * Eloquent, or left with a subtype whose registration is gone. PageObserver
 * refuses such a pair only when a write changes it, so these rows keep
 * rendering; this is the operator's list of them (trashed included).
 */
final class PageTypePairsCheck implements HealthCheck
{
    private const int SAMPLE_IDS = 5;

    public function __construct(private readonly PageTypeRegistry $registry) {}

    public function id(): string
    {
        return 'page_type_pairs';
    }

    public function run(): HealthCheckResult
    {
        $pairs = $this->invalidPairs();

        if ($pairs === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.page_type_pairs.label',
                messageKey: 'tbtop-cms::health.checks.page_type_pairs.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.page_type_pairs.label',
            messageKey: 'tbtop-cms::health.checks.page_type_pairs.warning',
            messageReplace: ['pairs' => implode('; ', $pairs)],
            hintKey: 'tbtop-cms::health.checks.page_type_pairs.hints.warning',
        );
    }

    /** @return list<string> "type=concert: 2 (#3, #5)", "type=page subtype=concert: 1 (#7)" */
    private function invalidPairs(): array
    {
        $pairs = Page::withTrashed()->toBase()
            ->select(['type', 'subtype'])->distinct()
            ->orderBy('type')->orderBy('subtype')
            ->get();
        $lines = [];

        foreach ($pairs as $pair) {
            $type = (string) $pair->type;
            $subtype = is_string($pair->subtype) ? $pair->subtype : null;

            if (PageTypePairProblem::of($type, $subtype, $this->registry) === null) {
                continue;
            }

            $rows = Page::withTrashed()->where('type', $type)
                ->when($subtype === null, fn ($q) => $q->whereNull('subtype'), fn ($q) => $q->where('subtype', $subtype));
            $count = (clone $rows)->count();
            $ids = (clone $rows)->orderBy('id')->limit(self::SAMPLE_IDS)->pluck('id')
                ->map(static fn (mixed $id): string => '#' . $id)->implode(', ');
            $more = $count > self::SAMPLE_IDS ? ', …' : '';
            $label = $subtype === null ? "type={$type}" : "type={$type} subtype={$subtype}";

            $lines[] = "{$label}: {$count} ({$ids}{$more})";
        }

        return $lines;
    }
}
