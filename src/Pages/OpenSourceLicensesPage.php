<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerOpenSourceLicensesAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmOpenSourceLicensesAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\ThirdPartyLicensesWriter;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class OpenSourceLicensesPage extends Page
{
    use InteractsWithPassportNavigation;
    use LoadsPassportDataLazily;

    protected static ?string $slug = 'developer-support/open-source-licenses';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     license_text: string,
     *     license_file: string|null,
     *     has_license_text: bool
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
     *     license_text: string,
     *     license_file: string|null,
     *     has_license_text: bool
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

    public function getView(): string
    {
        return 'filament-project-passport::pages.open-source-licenses';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * @return array<int, mixed>
     */
    public static function getNavigationItems(): array
    {
        return [];
    }

    protected static function passportPageKey(): string
    {
        return 'open_source_licenses';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'Open Source Licenses';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-document-text';
    }

    protected static function passportDefaultSort(): int
    {
        return 5;
    }

    /**
     * Bust cache and re-scan package license files.
     */
    public function refreshOpenSourceLicenses(): void
    {
        $composer = app(ComposerOpenSourceLicensesAuditor::class)->refresh();
        $npm = app(NpmOpenSourceLicensesAuditor::class)->refresh();
        app(ThirdPartyLicensesWriter::class)->write($composer['packages'], $npm['packages']);

        $this->packages = $composer['packages'];
        $this->checkedAt = $composer['checked_at'];
        $this->fromCache = false;

        $this->npmPackages = $npm['packages'];
        $this->npmCheckedAt = $npm['checked_at'];
        $this->npmFromCache = false;
        $this->ready = true;

        Notification::make()
            ->title('Open source licenses refreshed')
            ->body('Composer and NPM package license files were re-read and THIRD-PARTY-LICENSES.txt was updated.')
            ->success()
            ->send();
    }

    protected function hydratePassportData(): void
    {
        $composer = app(ComposerOpenSourceLicensesAuditor::class)->audit();
        $npm = app(NpmOpenSourceLicensesAuditor::class)->audit();

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
        // Livewire re-renders filtered accordion items.
    }

    public function updatedNpmSearch(): void
    {
        // Livewire re-renders filtered NPM accordion items.
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredPackagesProperty(): array
    {
        return $this->filterPackages($this->packages, $this->search);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredNpmPackagesProperty(): array
    {
        return $this->filterPackages($this->npmPackages, $this->npmSearch);
    }

    public function packagesWithLicenseTextCount(): int
    {
        return $this->countWithLicenseText($this->packages);
    }

    public function npmPackagesWithLicenseTextCount(): int
    {
        return $this->countWithLicenseText($this->npmPackages);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    private function filterPackages(array $packages, string $search): array
    {
        $needle = trim(mb_strtolower($search));

        if ($needle === '') {
            return $packages;
        }

        return array_values(array_filter(
            $packages,
            function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(
                    $row['name'].' '.$row['version'].' '.$row['license_label']
                );

                return str_contains($haystack, $needle);
            }
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    private function countWithLicenseText(array $packages): int
    {
        return count(array_filter(
            $packages,
            fn (array $package): bool => (bool) ($package['has_license_text'] ?? false)
        ));
    }
}
