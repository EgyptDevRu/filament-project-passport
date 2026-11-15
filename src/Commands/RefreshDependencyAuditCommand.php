<?php

namespace EgyptDevRu\FilamentProjectPassport\Commands;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use Illuminate\Console\Command;

class RefreshDependencyAuditCommand extends Command
{
    protected $signature = 'filament-project-passport:refresh-dependency-audit {--force : Refresh even if the cache is still fresh}';

    protected $description = 'Refresh Composer and NPM dependency audit caches (forced on Sundays; otherwise only when missing or stale)';

    public function handle(ComposerDependencyAuditor $composer, NpmDependencyAuditor $npm): int
    {
        try {
            $force = (bool) $this->option('force') || now()->isSunday();
            $exit = self::SUCCESS;

            $exit = min($exit, $this->refreshOne(
                label: 'Composer dependency audit',
                force: $force,
                shouldRefresh: $composer->shouldRefresh(),
                refresh: fn (): array => $composer->refresh(),
                current: fn (): array => $composer->audit(),
            ));

            if (! $npm->nodeIsAvailable()) {
                $this->info('Skipping NPM dependency audit: Node.js / npm is not available on this server.');
            } elseif (! $npm->hasPackageManifest()) {
                $this->info('Skipping NPM dependency audit: no package.json / package-lock.json in the application root.');
            } else {
                $exit = min($exit, $this->refreshOne(
                    label: 'NPM dependency audit',
                    force: $force,
                    shouldRefresh: $npm->shouldRefresh(),
                    refresh: fn (): array => $npm->refresh(),
                    current: fn (): array => $npm->audit(),
                ));
            }

            return $exit;
        } catch (\Throwable $exception) {
            $composer->rememberFailure($exception->getMessage());
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $composer->clearRefreshRunning();
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $refresh
     * @param  callable(): array<string, mixed>  $current
     */
    private function refreshOne(
        string $label,
        bool $force,
        bool $shouldRefresh,
        callable $refresh,
        callable $current,
    ): int {
        if (! $force && ! $shouldRefresh) {
            $cached = $current();
            $this->info(sprintf(
                '%s cache is still fresh (checked at %s). Skipping.',
                $label,
                (string) ($cached['checked_at'] ?? 'unknown'),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Refreshing %s…', strtolower($label)));

        $result = $refresh();

        if (! empty($result['error'])) {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. %d outdated, %d advisories. Checked at %s',
            (int) ($result['outdated_count'] ?? 0),
            (int) ($result['advisory_count'] ?? 0),
            (string) ($result['checked_at'] ?? 'unknown'),
        ));

        return self::SUCCESS;
    }
}
