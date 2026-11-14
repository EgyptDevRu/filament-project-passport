<?php

namespace EgyptDevRu\FilamentProjectPassport\Commands;

use EgyptDevRu\FilamentProjectPassport\Services\ComposerOpenSourceLicensesAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmOpenSourceLicensesAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\ThirdPartyLicensesWriter;
use Illuminate\Console\Command;

class RefreshOpenSourceLicensesCommand extends Command
{
    protected $signature = 'filament-project-passport:refresh-open-source-licenses {--force : Refresh even if the cache is less than 14 days}';

    protected $description = 'Refresh Composer and NPM open source license text caches and rewrite THIRD-PARTY-LICENSES.txt when missing or at least 14 days old';

    public function handle(
        ComposerOpenSourceLicensesAuditor $composer,
        NpmOpenSourceLicensesAuditor $npm,
        ThirdPartyLicensesWriter $thirdParty,
    ): int {
        $force = (bool) $this->option('force');

        $this->refreshAuditor(
            label: 'Composer open source licenses',
            shouldRefresh: $force || $composer->shouldRefresh(14),
            refresh: fn (): array => $composer->refresh(),
            current: fn (): array => $composer->audit(),
        );

        $this->refreshAuditor(
            label: 'NPM open source licenses',
            shouldRefresh: $force || $npm->shouldRefresh(14),
            refresh: fn (): array => $npm->refresh(),
            current: fn (): array => $npm->audit(),
        );

        $this->info('Writing '.ThirdPartyLicensesWriter::FILENAME.'…');

        $written = $thirdParty->refresh(fresh: $force);

        $this->info(sprintf(
            'Done. Wrote %d packages to %s',
            $written['packages'],
            $written['path'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array{packages: list<mixed>, checked_at: string}  $refresh
     * @param  callable(): array{packages: list<mixed>, checked_at: string}  $current
     */
    private function refreshAuditor(string $label, bool $shouldRefresh, callable $refresh, callable $current): void
    {
        if (! $shouldRefresh) {
            $cached = $current();
            $this->info(sprintf(
                '%s cache is still fresh (checked at %s). Skipping.',
                $label,
                $cached['checked_at'],
            ));

            return;
        }

        $this->info(sprintf('Refreshing %s…', strtolower($label)));

        $result = $refresh();

        $this->info(sprintf(
            'Done. %d packages scanned. Checked at %s',
            count($result['packages']),
            $result['checked_at'],
        ));
    }
}
