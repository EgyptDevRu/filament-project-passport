<?php

namespace EgyptDevRu\FilamentProjectPassport\Jobs;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs Composer outdated/audit off the HTTP request (requires a queue worker).
 */
class RefreshDependencyAuditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Must stay comfortably above ComposerDependencyAuditor::MAX_SCAN_SECONDS
     * (600s) so the queue worker never kills the job mid-scan before that
     * internal cap would; the 60s gap covers bootstrap/cache-write overhead.
     */
    public int $timeout = ComposerDependencyAuditor::MAX_SCAN_SECONDS + 60;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public bool $force = true) {}

    public function handle(ComposerDependencyAuditor $auditor): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(ComposerDependencyAuditor::MAX_SCAN_SECONDS);
        }

        try {
            if ($this->force) {
                $auditor->refresh();
            } else {
                $auditor->audit();
            }
        } catch (Throwable $exception) {
            $auditor->rememberFailure($exception->getMessage());

            throw $exception;
        } finally {
            $auditor->clearRefreshRunning();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $auditor = app(ComposerDependencyAuditor::class);

        if ($auditor->completedPayload() === null) {
            $auditor->rememberFailure(
                $exception?->getMessage() ?: 'Dependency audit job failed or timed out.'
            );
        }

        $auditor->clearRefreshRunning();
    }
}
