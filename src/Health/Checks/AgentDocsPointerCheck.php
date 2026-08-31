<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\Concerns\ReadsFiles;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * `docs/host/ai/README.md` is only found by an agent if the host's
 * `AGENTS.md`/`CLAUDE.md` points to it; missing it never escalates past a warning.
 */
final class AgentDocsPointerCheck implements HealthCheck
{
    use ReadsFiles;

    private const string POINTER_NEEDLE = 'docs/host/ai';

    /** @var list<string> */
    private const array CANDIDATES = ['AGENTS.md', 'CLAUDE.md'];

    public function id(): string
    {
        return 'agent_docs_pointer';
    }

    public function run(): HealthCheckResult
    {
        foreach (self::CANDIDATES as $relative) {
            $path = base_path($relative);

            if (! File::isFile($path)) {
                continue;
            }

            $source = $this->readFile($path);

            if ($source !== null && str_contains($source, self::POINTER_NEEDLE)) {
                return new HealthCheckResult(
                    checkId: $this->id(),
                    status: HealthStatus::Ok,
                    labelKey: 'tbtop-cms::health.checks.agent_docs_pointer.label',
                    messageKey: 'tbtop-cms::health.checks.agent_docs_pointer.ok',
                );
            }
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.agent_docs_pointer.label',
            messageKey: 'tbtop-cms::health.checks.agent_docs_pointer.missing',
            hintKey: 'tbtop-cms::health.checks.agent_docs_pointer.hints.missing',
            hintReplace: ['candidates' => implode(', ', self::CANDIDATES)],
        );
    }
}
