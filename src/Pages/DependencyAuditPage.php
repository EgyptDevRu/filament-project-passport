<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\ReleasesSessionEarly;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class DependencyAuditPage extends Page
{
    use InteractsWithPassportNavigation;
    use ReleasesSessionEarly;

    protected static ?string $slug = 'developer-support/dependency-audit';

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $audit = [];

    /**
     * True while a background Composer audit is in progress.
     * The page always renders immediately (empty/placeholder or cache).
     */
    public bool $scanning = false;

    /**
     * True after wire:init / refresh has asked for a background run.
     * Used so polls can stop the spinner when the worker dies without writing cache.
     */
    public bool $refreshRequested = false;

    public string $search = '';

    /**
     * Only ever set via sortBy().
     */
    #[Locked]
    public string $sortColumn = 'name';

    #[Locked]
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

    /**
     * First HTML paint: cache hit or empty placeholder — never runs Composer.
     */
    public function mount(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache(requestRefresh: false);
    }

    /**
     * Runs on every Livewire request (including polls) — unlock session immediately.
     */
    public function hydrate(): void
    {
        $this->releaseSessionLockEarly();
    }

    /**
     * Filament-style deferred load: kick off background work once.
     * Must return immediately — Composer never runs inside this Livewire request.
     */
    public function loadPageData(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache(requestRefresh: true);
    }

    /**
     * Poll only — never re-dispatch. Stops the spinner when the worker finishes or dies.
     */
    public function pollPageData(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache(requestRefresh: false);
    }

    /**
     * Front-end 3-minute guard: queue unavailable / stuck worker / broken parent install.
     */
    public function failScanTimeout(): void
    {
        $this->releaseSessionLockEarly();

        if (! $this->scanning || filled($this->audit['error'] ?? null)) {
            return;
        }

        $completed = app(ComposerDependencyAuditor::class)->completedPayload();

        if ($completed !== null) {
            $this->audit = $completed;
            $this->scanning = false;

            return;
        }

        $this->audit = app(ComposerDependencyAuditor::class)->rememberFailure(
            'Dependency audit is still updating after 3 minutes. Check that a queue worker is running for this application (php artisan queue:work), or set dependency_audit.use_queue to false in the Passport config. This usually means the parent installation is not processing background jobs.'
        );
        $this->scanning = false;
    }

    /**
     * Bust cache and re-run composer outdated/audit in the background.
     */
    public function refreshDependencyAudit(): void
    {
        $auditor = app(ComposerDependencyAuditor::class);
        $auditor->forgetCache();
        $auditor->clearRefreshRunning();

        $this->audit = $auditor->placeholderPayload();
        $this->scanning = true;
        $this->refreshRequested = true;
        $auditor->dispatchBackgroundRefresh(force: true);

        Notification::make()
            ->title('Dependency audit started')
            ->body('Composer is re-checking outdated packages and advisories in the background. This can take 1–2 minutes.')
            ->success()
            ->send();
    }

    /**
     * @param  bool  $requestRefresh  Schedule background work (queue or detached artisan).
     */
    protected function syncFromCache(bool $requestRefresh): void
    {
        // Let reload / other tabs proceed while we only peek cache + maybe spawn work.
        $this->releaseSessionLockEarly();

        $auditor = app(ComposerDependencyAuditor::class);

        $completed = $auditor->completedPayload();

        if ($completed !== null) {
            $this->audit = $completed;
            $this->scanning = false;

            return;
        }

        if ($this->audit === [] || ! array_key_exists('outdated', $this->audit)) {
            $this->audit = $auditor->placeholderPayload();
        }

        if ($auditor->isRefreshRunning()) {
            $this->scanning = true;

            return;
        }

        if ($requestRefresh) {
            $this->refreshRequested = true;
            // No-op if a refresh is already running (other tab / previous visit).
            $auditor->dispatchBackgroundRefresh(force: true);
            $this->scanning = true;

            return;
        }

        // Poll: worker finished (or crashed) without writing a result — leave loading state.
        if ($this->refreshRequested) {
            $this->audit = $auditor->rememberFailure(
                'Dependency audit did not complete. The background worker may have timed out or failed.'
            );
            $this->scanning = false;

            return;
        }

        $this->scanning = true;
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
