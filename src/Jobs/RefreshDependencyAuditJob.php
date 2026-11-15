<?php

namespace EgyptDevRu\FilamentProjectPassport\Jobs;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\PhpRuntimeStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs Composer + NPM outdated/audit off the HTTP request (requires a queue worker).
 */
class RefreshDependencyAuditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Composer scan + NPM outdated/audit + bootstrap buffer. Must stay above both
     * auditors' internal caps so the worker does not kill the job mid-npm after
     * Composer already wrote a successful cache.
     */
    public const int TIMEOUT_SECONDS = ComposerDependencyAuditor::MAX_SCAN_SECONDS
        + NpmDependencyAuditor::MAX_SCAN_SECONDS
        + 60;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public bool $force = true) {}

    public function handle(ComposerDependencyAuditor $composer, NpmDependencyAuditor $npm): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIMEOUT_SECONDS);
        }

        try {
            if ($this->force || PhpRuntimeStatus::shouldRefresh()) {
                PhpRuntimeStatus::refresh();
            }

            if ($this->force) {
                $composer->refresh();
            } else {
                $composer->audit();
            }

            $this->refreshNpm($npm);
        } catch (Throwable $exception) {
            if ($composer->completedPayload() === null) {
                $composer->rememberFailure($exception->getMessage());
            }

            if ($npm->needsBackgroundScan()) {
                $npm->rememberFailure($exception->getMessage());
            }

            throw $exception;
        } finally {
            $composer->clearRefreshRunning();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);
        $message = $exception?->getMessage() ?: 'Dependency audit job failed or timed out.';

        // Composer may already have a successful payload if the worker died during NPM.
        if ($composer->completedPayload() === null) {
            $composer->rememberFailure($message);
        }

        if ($npm->needsBackgroundScan()) {
            $npm->rememberFailure($message);
        }

        $composer->clearRefreshRunning();
    }

    private function refreshNpm(NpmDependencyAuditor $npm): void
    {
        // Skip when Node is missing or the host has no package.json (Blade/Alpine-only).
        if (! $npm->isRunnable()) {
            return;
        }

        if ($this->force) {
            $npm->refresh();

            return;
        }

        $npm->audit();
    }
}
