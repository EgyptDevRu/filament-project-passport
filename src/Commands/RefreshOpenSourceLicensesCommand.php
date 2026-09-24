<?php

namespace EgyptDevRu\FilamentProjectPassport\Commands;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerOpenSourceLicensesAuditor;
use Illuminate\Console\Command;

class RefreshOpenSourceLicensesCommand extends Command
{
    protected $signature = 'filament-project-passport:refresh-open-source-licenses {--force : Refresh even if the cache is less than 14 days}';

    protected $description = 'Refresh open source license text cache when it is missing or at least 14 days old';

    public function handle(ComposerOpenSourceLicensesAuditor $auditor): int
    {
        if (! $this->option('force') && ! $auditor->shouldRefresh(14)) {
            $current = $auditor->audit();
            $this->info(sprintf(
                'Open source licenses cache is still fresh (checked at %s). Skipping.',
                $current['checked_at'],
            ));

            return self::SUCCESS;
        }

        $this->info('Refreshing open source licenses…');

        $result = $auditor->refresh();

        $this->info(sprintf(
            'Done. %d packages scanned. Checked at %s',
            count($result['packages']),
            $result['checked_at'],
        ));

        return self::SUCCESS;
    }
}
