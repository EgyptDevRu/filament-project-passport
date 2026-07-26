<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class LicenseAuditPage extends Page
{
    use InteractsWithPassportNavigation;

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

    #[Locked]
    public ?string $checkedAt = null;

    #[Locked]
    public bool $fromCache = false;

    public string $search = '';

    public string $sortColumn = 'name';

    public string $sortDirection = 'asc';

    private mixed $incompatiblePackages;

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

    public function mount(): void
    {
        $this->loadAudit();
    }

    /**
     * Bust cache and re-scan packages (easter-egg: type "refresh").
     */
    public function refreshLicenseAudit(): void
    {
        $result = app(ComposerLicenseAuditor::class)->refresh();

        $this->packages = $result['packages'];
        $this->checkedAt = $result['checked_at'];
        $this->fromCache = false;

        Notification::make()
            ->title('License audit refreshed')
            ->body('Composer package licenses were re-checked for this application.')
            ->success()
            ->send();
    }

    protected function loadAudit(): void
    {
        $result = app(ComposerLicenseAuditor::class)->audit();

        $this->packages = $result['packages'];
        $this->checkedAt = $result['checked_at'];
        $this->fromCache = $result['from_cache'];
    }

    public function lastCheckSummary(): string
    {
        return 'Last check: '.CheckAge::label($this->checkedAt);
    }

    public function updatedSearch(): void
    {
        // Livewire re-renders filtered rows.
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

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredPackagesProperty(): array
    {
        $rows = $this->packages;
        $search = trim(mb_strtolower($this->search));

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($search): bool {
                    $haystack = mb_strtolower(
                        $row['name'].' '.$row['version'].' '.$row['license_label'].' '.$row['status_label']
                    );

                    return str_contains($haystack, $search);
                }
            ));
        }

        $column = $this->sortColumn;
        $direction = $this->sortDirection === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($column, $direction): int {
            $left = (string) ($a[$column] ?? '');
            $right = (string) ($b[$column] ?? '');

            return $direction * strcasecmp($left, $right);
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIncompatiblePackagesProperty(): array
    {
        return app(ComposerLicenseAuditor::class)->incompatiblePackages($this->packages);
    }

    public function hasIncompatiblePackages(): bool
    {
        return $this->incompatiblePackages !== [];
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            LicenseStatus::COMPATIBLE => 'fi-pp-badge fi-pp-badge--success',
            LicenseStatus::INCOMPATIBLE => 'fi-pp-badge fi-pp-badge--danger',
            default => 'fi-pp-badge fi-pp-badge--warning',
        };
    }
}
