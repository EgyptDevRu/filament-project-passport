<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerOpenSourceLicensesAuditor;
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

    #[Locked]
    public ?string $checkedAt = null;

    #[Locked]
    public bool $fromCache = false;

    public string $search = '';

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
        $result = app(ComposerOpenSourceLicensesAuditor::class)->refresh();

        $this->packages = $result['packages'];
        $this->checkedAt = $result['checked_at'];
        $this->fromCache = false;
        $this->ready = true;

        Notification::make()
            ->title('Open source licenses refreshed')
            ->body('Composer package license files were re-read for this application.')
            ->success()
            ->send();
    }

    protected function hydratePassportData(): void
    {
        $result = app(ComposerOpenSourceLicensesAuditor::class)->audit();

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
        // Livewire re-renders filtered accordion items.
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredPackagesProperty(): array
    {
        $rows = $this->packages;
        $search = trim(mb_strtolower($this->search));

        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            function (array $row) use ($search): bool {
                $haystack = mb_strtolower(
                    $row['name'].' '.$row['version'].' '.$row['license_label']
                );

                return str_contains($haystack, $search);
            }
        ));
    }

    public function packagesWithLicenseTextCount(): int
    {
        return count(array_filter(
            $this->packages,
            fn (array $package): bool => (bool) ($package['has_license_text'] ?? false)
        ));
    }
}
