<?php

namespace EgyptDevRu\FilamentProjectPassport\Commands;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use Illuminate\Console\Command;

class RefreshDependencyAuditCommand extends Command
{
    protected $signature = 'filament-project-passport:refresh-dependency-audit {--force : Refresh even if the cache is still fresh}';

    protected $description = 'Refresh Composer dependency audit cache (forced on Sundays; otherwise only when missing or stale)';

    public function handle(ComposerDependencyAuditor $auditor): int
    {
        $force = (bool) $this->option('force') || now()->isSunday();

        if (! $force && ! $auditor->shouldRefresh()) {
            $current = $auditor->audit();
            $this->info(sprintf(
                'Dependency audit cache is still fresh (checked at %s). Skipping.',
                (string) ($current['checked_at'] ?? 'unknown'),
            ));

            return self::SUCCESS;
        }

        $this->info('Refreshing dependency audit…');

        $result = $auditor->refresh();

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
