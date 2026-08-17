<?php

declare(strict_types=1);

namespace Tbtop\Cms\Console;

use Illuminate\Console\Command;
use Tbtop\Cms\Health\HealthCheckRunner;
use Tbtop\Cms\Health\HealthReport;
use Tbtop\Cms\Health\HealthStatus;

class DoctorCommand extends Command
{
    protected $signature = 'cms:doctor';

    protected $description = 'Run the CMS health checks and report anything that needs attention.';

    public function handle(HealthCheckRunner $runner): int
    {
        $report = $runner->run();

        foreach ($report->results as $result) {
            $this->line($this->statusPrefix($result->status) . ' ' . $result->label() . ': ' . $result->message());

            $hint = $result->hint();
            if ($hint !== null) {
                $this->line('  ' . $hint);
            }
        }

        $this->newLine();
        $this->summarize($report);

        return $report->exitCode();
    }

    /**
     * Localized like every other user-facing health string — the check
     * messages already render in the app locale, and a mixed-language line
     * ("Пульс планувальника ... [WARN]") reads like a bug in itself.
     */
    private function statusPrefix(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>[' . __('tbtop-cms::health.console.ok') . ']</>',
            HealthStatus::Warning => '<fg=yellow>[' . __('tbtop-cms::health.console.warning') . ']</>',
            HealthStatus::Error => '<fg=red>[' . __('tbtop-cms::health.console.error') . ']</>',
        };
    }

    private function summarize(HealthReport $report): void
    {
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($report->results as $result) {
            $counts[$result->status->value]++;
        }

        $summary = __('tbtop-cms::health.console.summary', [
            'ok' => $counts['ok'],
            'warnings' => $counts['warning'],
            'errors' => $counts['error'],
        ]);

        if ($report->hasErrors()) {
            $this->error($summary);

            return;
        }

        $this->info($summary);
    }
}
