<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\ThirdPartyLicensesWriter;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class LicenseAuditPage extends Page
{
    use InteractsWithPassportNavigation;
    use LoadsPassportDataLazily;

    protected static ?string $slug = 'developer-support/license-audit';

    /**
     * @var list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     status: string,
     *     status_label: string
     * }>
     */
    #[Locked]
    public array $packages = [];

    /**
     * @var list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     status: string,
     *     status_label: string
     * }>
     */
    #[Locked]
    public array $npmPackages = [];

    #[Locked]
    public ?string $checkedAt = null;

    #[Locked]
    public ?string $npmCheckedAt = null;

    #[Locked]
    public bool $fromCache = false;

    #[Locked]
    public bool $npmFromCache = false;

    public string $search = '';

    public string $npmSearch = '';

    /**
     * Only ever set via sortBy().
     */
    #[Locked]
    public string $sortColumn = 'name';

    #[Locked]
    public string $sortDirection = 'asc';

    #[Locked]
    public string $npmSortColumn = 'name';

    #[Locked]
    public string $npmSortDirection = 'asc';

    public function getView(): string
    {
        return 'filament-project-passport::pages.license-audit';
    }

    protected static function passportPageKey(): string
    {
        return 'license_audit';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'License Audit';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-scale';
    }

    protected static function passportDefaultSort(): int
    {
        return 3;
    }

    /**
     * Bust cache and re-scan packages.
     */
    public function refreshLicenseAudit(): void
    {
        $composer = app(ComposerLicenseAuditor::class)->refresh();
        $npm = app(NpmLicenseAuditor::class)->refresh();
        app(ThirdPartyLicensesWriter::class)->refresh(fresh: true);

        $this->packages = $composer['packages'];
        $this->checkedAt = $composer['checked_at'];
        $this->fromCache = false;

        $this->npmPackages = $npm['packages'];
        $this->npmCheckedAt = $npm['checked_at'];
        $this->npmFromCache = false;
        $this->ready = true;

        Notification::make()
            ->title('License audit refreshed')
            ->body('Composer and NPM package licenses were re-checked and THIRD-PARTY-LICENSES.txt was updated.')
            ->success()
            ->send();
    }

    protected function hydratePassportData(): void
    {
        $composer = app(ComposerLicenseAuditor::class)->audit();
        $npm = app(NpmLicenseAuditor::class)->audit();

        $this->packages = $composer['packages'];
        $this->checkedAt = $composer['checked_at'];
        $this->fromCache = $composer['from_cache'];

        $this->npmPackages = $npm['packages'];
        $this->npmCheckedAt = $npm['checked_at'];
        $this->npmFromCache = $npm['from_cache'];
    }

    public function lastCheckSummary(): string
    {
        return 'Last check: '.CheckAge::label($this->checkedAt);
    }

    public function npmLastCheckSummary(): string
    {
        return 'Last check: '.CheckAge::label($this->npmCheckedAt);
    }

    public function updatedSearch(): void
    {
        // Livewire re-renders filtered rows.
    }

    public function updatedNpmSearch(): void
    {
        // Livewire re-renders filtered NPM rows.
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['name', 'version', 'license_label', 'status'], true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = 'asc';
    }

    public function sortNpmBy(string $column): void
    {
        if (! in_array($column, ['name', 'version', 'license_label', 'status'], true)) {
            return;
        }

        if ($this->npmSortColumn === $column) {
            $this->npmSortDirection = $this->npmSortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->npmSortColumn = $column;
        $this->npmSortDirection = 'asc';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredPackagesProperty(): array
    {
        return $this->filterAndSortPackages(
            $this->packages,
            $this->search,
            $this->sortColumn,
            $this->sortDirection,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredNpmPackagesProperty(): array
    {
        return $this->filterAndSortPackages(
            $this->npmPackages,
            $this->npmSearch,
            $this->npmSortColumn,
            $this->npmSortDirection,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIncompatiblePackagesProperty(): array
    {
        return app(ComposerLicenseAuditor::class)->incompatiblePackages($this->packages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIncompatibleNpmPackagesProperty(): array
    {
        return app(NpmLicenseAuditor::class)->incompatiblePackages($this->npmPackages);
    }

    public function hasIncompatiblePackages(): bool
    {
        return $this->getIncompatiblePackagesProperty() !== []
            || $this->getIncompatibleNpmPackagesProperty() !== [];
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            LicenseStatus::COMPATIBLE => 'fi-pp-badge fi-pp-badge--success',
            LicenseStatus::INCOMPATIBLE => 'fi-pp-badge fi-pp-badge--danger',
            default => 'fi-pp-badge fi-pp-badge--warning',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    private function filterAndSortPackages(
        array $packages,
        string $search,
        string $sortColumn,
        string $sortDirection,
    ): array {
        $rows = $packages;
        $needle = trim(mb_strtolower($search));

        if ($needle !== '') {
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($needle): bool {
                    $haystack = mb_strtolower(
                        $row['name'].' '.$row['version'].' '.$row['license_label'].' '.$row['status_label']
                    );

                    return str_contains($haystack, $needle);
                }
            ));
        }

        $direction = $sortDirection === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($sortColumn, $direction): int {
            $left = (string) ($a[$sortColumn] ?? '');
            $right = (string) ($b[$sortColumn] ?? '');

            return $direction * strcasecmp($left, $right);
        });

        return $rows;
    }
}
