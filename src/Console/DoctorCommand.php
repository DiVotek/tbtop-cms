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

    private function statusPrefix(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>[OK]</>',
            HealthStatus::Warning => '<fg=yellow>[WARN]</>',
            HealthStatus::Error => '<fg=red>[FAIL]</>',
        };
    }

    private function summarize(HealthReport $report): void
    {
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($report->results as $result) {
            $counts[$result->status->value]++;
        }

        $summary = "{$counts['ok']} ok, {$counts['warning']} warning(s), {$counts['error']} error(s).";

        if ($report->hasErrors()) {
            $this->error($summary);

            return;
        }

        $this->info($summary);
    }
}
