<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\ReleasesSessionEarly;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use EgyptDevRu\FilamentProjectPassport\Support\PhpRuntimeStatus;
use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;

/**
 * Security overview: dependency advisory/outdated counts (links to Dependency Audit)
 * plus PHP2SHELL runtime status. Same RBAC as other Developer Support pages.
 */
class SecurityAuditPage extends Page
{
    use InteractsWithPassportNavigation;
    use ReleasesSessionEarly;

    protected static ?string $slug = 'developer-support/security-audit';

    private const string MANUAL_REFRESH_CACHE_KEY = 'filament-project-passport.security-audit.manual-refresh';

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

    public bool $refreshing = false;

    public function getView(): string
    {
        return 'filament-project-passport::pages.security-audit';
    }

    protected static function passportPageKey(): string
    {
        return 'security_audit';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'Security Audit';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-lock-closed';
    }

    protected static function passportDefaultSort(): int
    {
        return 4;
    }

    public function mount(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache();
    }

    public function hydrate(): void
    {
        $this->releaseSessionLockEarly();
    }

    /**
     * Deferred load — refresh counts from cache only (never runs Composer/npm here).
     */
    public function loadPageData(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache();
    }

    /**
     * Poll while a manual refresh is in progress.
     */
    public function pollPageData(): void
    {
        $this->releaseSessionLockEarly();
        $this->syncFromCache();

        if (! $this->refreshing) {
            return;
        }

        $composer = app(ComposerDependencyAuditor::class);

        if ($composer->isRefreshRunning()) {
            return;
        }

        // Success, failure, or abandoned job — daily slot was consumed when refresh started.
        $this->refreshing = false;
    }

    /**
     * Refresh lives in the page body only — never as a Filament header action.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * True when today's signed manual-refresh gate is absent.
     * Tampered Redis/DB values fail closed (treated as already used).
     */
    public function canManualRefresh(): bool
    {
        $cached = SignedCachePayload::open(Cache::get(self::MANUAL_REFRESH_CACHE_KEY));

        if ($cached === null) {
            // Missing key → allowed. Invalid/tampered payload → deny.
            return Cache::get(self::MANUAL_REFRESH_CACHE_KEY) === null;
        }

        $date = $cached['date'] ?? null;

        return ! is_string($date) || $date !== now()->toDateString();
    }

    public function manualRefreshHint(): string
    {
        if ($this->refreshing) {
            return 'Refresh in progress… counts update when the background check finishes.';
        }

        if ($this->canManualRefresh()) {
            return 'Manual refresh is available once per day (success or failure counts).';
        }

        return 'Refresh used today. Next available tomorrow (automatic schedule still runs).';
    }

    public function refreshButtonLabel(): string
    {
        if ($this->refreshing) {
            return 'Refreshing…';
        }

        return $this->canManualRefresh() ? 'Refresh' : 'Refresh used today';
    }

    /**
     * Manual once-per-day refresh of Composer/NPM dependency security data.
     * The daily slot is consumed immediately (including when the job later fails).
     */
    public function refreshSecurityAudit(): void
    {
        $this->releaseSessionLockEarly();

        if ($this->refreshing) {
            return;
        }

        if (! $this->canManualRefresh()) {
            Notification::make()
                ->title('Refresh used today')
                ->body('Manual security audit refresh is limited to once per day, including failed attempts. The automatic schedule still runs.')
                ->warning()
                ->send();

            return;
        }

        $this->markManualRefreshUsed();

        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);

        $composer->forgetCache();
        $npm->forgetCache();
        $composer->clearRefreshRunning();

        $this->audit = $composer->placeholderPayload();
        $this->npmAudit = $npm->placeholderPayload();
        $this->refreshing = true;

        $composer->dispatchBackgroundRefresh(force: true);

        $body = $npm->isRunnable()
            ? 'Composer and NPM security checks started in the background. This can take 1–2 minutes.'
            : 'Composer security check started in the background. This can take 1–2 minutes.';

        Notification::make()
            ->title('Security audit refresh started')
            ->body($body)
            ->success()
            ->send();
    }

    protected function syncFromCache(): void
    {
        $this->releaseSessionLockEarly();

        $composer = app(ComposerDependencyAuditor::class);
        $npm = app(NpmDependencyAuditor::class);

        $this->audit = $composer->completedPayload() ?? $composer->placeholderPayload();
        $this->npmAudit = $npm->completedPayload() ?? $npm->placeholderPayload();
    }

    public function dependencyAuditUrl(): string
    {
        try {
            return DependencyAuditPage::getUrl();
        } catch (\Throwable) {
            return url('/admin/developer-support/dependency-audit');
        }
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

    public function showsNpmDependencyResults(): bool
    {
        if (! app(NpmDependencyAuditor::class)->isRunnable()) {
            return false;
        }

        return ! (bool) ($this->npmAudit['skipped'] ?? false);
    }

    public function showsNpmNodeUnavailableNotice(): bool
    {
        return ! app(NpmDependencyAuditor::class)->nodeIsAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    public function phpRuntimeStatus(): array
    {
        return PhpRuntimeStatus::report();
    }

    private function markManualRefreshUsed(): void
    {
        Cache::put(
            self::MANUAL_REFRESH_CACHE_KEY,
            SignedCachePayload::seal([
                'date' => now()->toDateString(),
                'used_at' => now()->toIso8601String(),
            ]),
            now()->endOfDay()
        );
    }
}
