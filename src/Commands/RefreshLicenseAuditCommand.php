<?php

namespace EgyptDevRu\FilamentProjectPassport\Commands;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use Illuminate\Console\Command;

class RefreshLicenseAuditCommand extends Command
{
    protected $signature = 'filament-project-passport:refresh-license-audit {--force : Refresh even if the cache is less than 14 days}';

    protected $description = 'Refresh Composer license audit cache when it is missing or at least 14 days old';

    public function handle(ComposerLicenseAuditor $auditor): int
    {
        if (! $this->option('force') && ! $auditor->shouldRefresh(14)) {
            $current = $auditor->audit();
            $this->info(sprintf(
                'License audit cache is still fresh (checked at %s). Skipping.',
                $current['checked_at'],
            ));

            return self::SUCCESS;
        }

        $this->info('Refreshing license audit…');

        $result = $auditor->refresh();

        $this->info(sprintf(
            'Done. %d packages audited. Checked at %s',
            count($result['packages']),
            $result['checked_at'],
        ));

        return self::SUCCESS;
    }
}
