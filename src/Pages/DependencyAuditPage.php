<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\ReleasesSessionEarly;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
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
     * @var array<string, mixed>
     */
    #[Locked]
    public array $npmAudit = [];

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
        return 5;
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
     * Front-end guard when the background lock has cleared but results are still missing.
     * Does nothing while the refresh lock is held (Composer + NPM can exceed a few minutes).
     */
    public function failScanTimeout(): void
    {
        $this->releaseSessionLockEarly();

        if (! $this->scanning || filled($this->audit['error'] ?? null)) {
            return;
        }

        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);

        // Worker still running — keep polling; do not poison the NPM cache early.
        if ($composer->isRefreshRunning()) {
            return;
        }

        $composerCompleted = $composer->completedPayload();
        $npmCompleted = $npm->completedPayload();
        $npmReady = ! $npm->needsBackgroundScan();

        if ($composerCompleted !== null && $npmReady) {
            $this->audit = $composerCompleted;
            $this->npmAudit = $npmCompleted ?? $npm->placeholderPayload();
            $this->scanning = false;

            return;
        }

        if ($composerCompleted !== null) {
            $this->audit = $composerCompleted;
        }

        if ($npmCompleted !== null) {
            $this->npmAudit = $npmCompleted;
        }

        if ($composerCompleted === null) {
            $this->audit = $composer->rememberFailure(
                'Dependency audit did not finish. Check that a queue worker is running for this application (php artisan queue:work), or set dependency_audit.use_queue to false in the Passport config. This usually means the parent installation is not processing background jobs.'
            );
        }

        if ($npm->needsBackgroundScan()) {
            $this->npmAudit = $npm->rememberFailure(
                'NPM dependency audit did not finish. Check that a queue worker can run `npm` for this application.'
            );
        }

        $this->scanning = false;
    }

    /**
     * Bust cache and re-run composer/npm outdated/audit in the background.
     */
    public function refreshDependencyAudit(): void
    {
        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);

        $composer->forgetCache();
        $npm->forgetCache();
        $composer->clearRefreshRunning();

        $this->audit = $composer->placeholderPayload();
        $this->npmAudit = $npm->placeholderPayload();
        $this->scanning = true;
        $this->refreshRequested = true;
        $composer->dispatchBackgroundRefresh(force: true);

        $body = $npm->isRunnable()
            ? 'Composer and NPM are re-checking outdated packages and advisories in the background. This can take 1–2 minutes.'
            : 'Composer is re-checking outdated packages and advisories in the background. This can take 1–2 minutes.';

        Notification::make()
            ->title('Dependency audit started')
            ->body($body)
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

        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);

        $composerCompleted = $composer->completedPayload();
        $npmCompleted = $npm->completedPayload();

        if ($composerCompleted !== null) {
            $this->audit = $composerCompleted;
        } elseif ($this->audit === [] || ! array_key_exists('outdated', $this->audit)) {
            $this->audit = $composer->placeholderPayload();
        }

        $this->npmAudit = $npmCompleted ?? $npm->placeholderPayload();

        // Composer can be warm while NPM is still cold (e.g. after NPM support was added).
        // Treat the page as incomplete until both ecosystems that apply have a result.
        // When Node.js/npm is missing, NPM is skipped and does not block completion.
        $composerReady = $composerCompleted !== null;
        $npmReady = ! $npm->needsBackgroundScan();

        if ($composerReady && $npmReady) {
            $this->scanning = false;

            return;
        }

        if ($composer->isRefreshRunning()) {
            $this->scanning = true;

            return;
        }

        if ($requestRefresh) {
            $this->refreshRequested = true;
            // No-op if a refresh is already running (other tab / previous visit).
            $composer->dispatchBackgroundRefresh(force: true);
            $this->scanning = true;

            return;
        }

        // Poll: worker finished (or crashed) without writing a result — leave loading state.
        if ($this->refreshRequested) {
            if (! $composerReady) {
                $this->audit = $composer->rememberFailure(
                    'Dependency audit did not complete. The background worker may have timed out or failed.'
                );
            }

            if ($npm->needsBackgroundScan()) {
                $this->npmAudit = $npm->rememberFailure(
                    'NPM dependency audit did not complete. The background worker may have timed out or failed.'
                );
            }

            $this->scanning = false;

            return;
        }

        $this->scanning = true;
    }

    /**
     * Show NPM outdated/advisory tables and stat tiles (requires Node.js + package manifest).
     * Blade/Alpine hosts without package.json stay on Composer-only UI even if npm exists on PATH.
     */
    public function showsNpmDependencyResults(): bool
    {
        if (! app(NpmDependencyAuditor::class)->isRunnable()) {
            return false;
        }

        return ! (bool) ($this->npmAudit['skipped'] ?? false);
    }

    /**
     * Node.js / npm CLI missing — hide NPM result blocks and show the unavailable notice.
     * Not shown when the host simply has no package.json (no frontend NPM project).
     */
    public function showsNpmNodeUnavailableNotice(): bool
    {
        return ! app(NpmDependencyAuditor::class)->nodeIsAvailable();
    }

    public function lastCheckSummary(): string
    {
        $checkedAt = $this->audit['checked_at'] ?? null;

        return 'Last check: '.CheckAge::label(is_string($checkedAt) ? $checkedAt : null);
    }

    public function npmLastCheckSummary(): string
    {
        $checkedAt = $this->npmAudit['checked_at'] ?? null;

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

    public function highAdvisoryCount(): int
    {
        return (int) ($this->audit['high_advisory_count'] ?? 0);
    }

    public function npmOutdatedCount(): int
    {
        return (int) ($this->npmAudit['outdated_count'] ?? 0);
    }

    public function npmAdvisoryCount(): int
    {
        return (int) ($this->npmAudit['advisory_count'] ?? 0);
    }

    public function npmHighAdvisoryCount(): int
    {
        return (int) ($this->npmAudit['high_advisory_count'] ?? 0);
    }

    public function totalHighAdvisoryCount(): int
    {
        return $this->highAdvisoryCount() + $this->npmHighAdvisoryCount();
    }

    /**
     * High/critical advisories are still present after a successful check that is at least 7 days old.
     */
    public function shouldEscalateHighAdvisories(): bool
    {
        return $this->auditHasStaleHighAdvisories($this->audit, $this->highAdvisoryCount())
            || $this->auditHasStaleHighAdvisories($this->npmAudit, $this->npmHighAdvisoryCount());
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function auditHasStaleHighAdvisories(array $audit, int $highCount): bool
    {
        if ($highCount < 1) {
            return false;
        }

        $checkedAt = $audit['checked_at'] ?? null;

        if (! is_string($checkedAt) || $checkedAt === '' || filled($audit['error'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($checkedAt)->diffInDays(now()) >= ComposerDependencyAuditor::CACHE_FRESH_DAYS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{email: ?string, phone: ?string, developer_name: ?string}
     */
    public function studioContact(): array
    {
        $license = app(LicenseApiClient::class)->cachedPayload() ?? [];

        return [
            'email' => isset($license['email']) && is_string($license['email']) ? $license['email'] : null,
            'phone' => isset($license['phone']) && is_string($license['phone']) ? $license['phone'] : null,
            'developer_name' => isset($license['developer_name']) && is_string($license['developer_name']) ? $license['developer_name'] : null,
        ];
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

    public function sortNpmBy(string $column): void
    {
        if (! in_array($column, ['name', 'version', 'latest', 'status'], true)) {
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
    public function getFilteredOutdatedProperty(): array
    {
        return $this->filterOutdatedRows(
            array_values($this->audit['outdated'] ?? []),
            $this->search,
            $this->sortColumn,
            $this->sortDirection,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredNpmOutdatedProperty(): array
    {
        return $this->filterOutdatedRows(
            array_values($this->npmAudit['outdated'] ?? []),
            $this->npmSearch,
            $this->npmSortColumn,
            $this->npmSortDirection,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAdvisoriesProperty(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->audit['advisories'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNpmAdvisoriesProperty(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->npmAudit['advisories'] ?? []);
    }

    public function hasIssues(): bool
    {
        return $this->outdatedCount() > 0
            || $this->advisoryCount() > 0
            || $this->npmOutdatedCount() > 0
            || $this->npmAdvisoryCount() > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterOutdatedRows(array $rows, string $search, string $sortColumn, string $sortDirection): array
    {
        $needle = trim(mb_strtolower($search));

        if ($needle !== '') {
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($needle): bool {
                    $haystack = mb_strtolower(
                        ($row['name'] ?? '').' '.($row['version'] ?? '').' '.($row['latest'] ?? '').' '.($row['status'] ?? '')
                    );

                    return str_contains($haystack, $needle);
                }
            ));
        }

        $direction = $sortDirection === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($sortColumn, $direction): int {
            return $direction * strcasecmp((string) ($a[$sortColumn] ?? ''), (string) ($b[$sortColumn] ?? ''));
        });

        return $rows;
    }
}
