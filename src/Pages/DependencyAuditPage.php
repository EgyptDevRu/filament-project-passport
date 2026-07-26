<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class DependencyAuditPage extends Page
{
    use InteractsWithPassportNavigation;

    protected static ?string $slug = 'developer-support/dependency-audit';

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $audit = [];

    public string $search = '';

    public string $sortColumn = 'name';

    public string $sortDirection = 'asc';

    public function getView(): string
    {
        return 'filament-project-passport::pages.dependency-audit';
    }

    protected static function passportPageKey(): string
    {
        return 'dependency_audit';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'Dependency Audit';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-cube';
    }

    protected static function passportDefaultSort(): int
    {
        return 4;
    }

    public function mount(): void
    {
        $this->loadAudit();
    }

    /**
     * Bust cache and re-run composer outdated/audit (easter-egg: type "refresh").
     */
    public function refreshDependencyAudit(): void
    {
        $this->audit = app(ComposerDependencyAuditor::class)->refresh();

        Notification::make()
            ->title('Dependency audit refreshed')
            ->body('Composer outdated packages and security advisories were re-checked.')
            ->success()
            ->send();
    }

    protected function loadAudit(): void
    {
        $this->audit = app(ComposerDependencyAuditor::class)->audit();
    }

    public function lastCheckSummary(): string
    {
        $checkedAt = $this->audit['checked_at'] ?? null;

        return 'Last check: '.CheckAge::label(is_string($checkedAt) ? $checkedAt : null);
    }

    public function outdatedCount(): int
    {
        return (int) ($this->audit['outdated_count'] ?? 0);
    }

    public function advisoryCount(): int
    {
        return (int) ($this->audit['advisory_count'] ?? 0);
    }

    /**
     * @return array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool}
     */
    public function laravelStatus(): array
    {
        /** @var array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool} */
        return $this->audit['laravel'] ?? [
            'installed' => null,
            'latest' => null,
            'up_to_date' => false,
            'installed_flag' => false,
        ];
    }

    /**
     * @return array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool}
     */
    public function filamentStatus(): array
    {
        /** @var array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool} */
        return $this->audit['filament'] ?? [
            'installed' => null,
            'latest' => null,
            'up_to_date' => false,
            'installed_flag' => false,
        ];
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['name', 'version', 'latest', 'status'], true)) {
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
    public function getFilteredOutdatedProperty(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($this->audit['outdated'] ?? []);
        $search = trim(mb_strtolower($this->search));

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($search): bool {
                    $haystack = mb_strtolower(
                        ($row['name'] ?? '').' '.($row['version'] ?? '').' '.($row['latest'] ?? '').' '.($row['status'] ?? '')
                    );

                    return str_contains($haystack, $search);
                }
            ));
        }

        $column = $this->sortColumn;
        $direction = $this->sortDirection === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($column, $direction): int {
            return $direction * strcasecmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? ''));
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAdvisoriesProperty(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->audit['advisories'] ?? []);
    }

    public function hasIssues(): bool
    {
        return $this->outdatedCount() > 0 || $this->advisoryCount() > 0;
    }
}
