@php
    $dependencyUrl = $this->dependencyAuditUrl();
@endphp

<div class="fi-pp-security-audit">
    <section class="fi-pp-card">
        <header class="fi-pp-card__header fi-pp-security-audit__header">
            <div class="fi-pp-security-audit__header-main">
                <h3 class="fi-pp-card__title">Security Audit</h3>
                <p class="fi-pp-card__desc">
                    High-level security signals for this installation. Dependency counts open the full
                    <a href="{{ $dependencyUrl }}">Dependency Audit</a>
                    page. PHP2SHELL reports the runtime PHP patch level for this server.
                </p>
                @include('filament-project-passport::components.cache-status', [
                    'checkedAt' => $this->audit['checked_at'] ?? null,
                    'fromCache' => (bool) ($this->audit['from_cache'] ?? false),
                    'scheduleHint' => 'Dependency data is refreshed with Dependency Audit (daily at 03:00 — forced on Sundays, otherwise when the cache is older than 7 days). Manual refresh on this page is limited to once per day.',
                ])
                <p class="fi-pp-muted">{{ $this->manualRefreshHint() }}</p>
            </div>

            <div class="fi-pp-security-audit__header-actions">
                <x-filament::button
                    color="{{ $this->canManualRefresh() ? 'primary' : 'gray' }}"
                    icon="heroicon-o-arrow-path"
                    wire:click="refreshSecurityAudit"
                    :disabled="! $this->canManualRefresh() || $this->refreshing"
                    wire:loading.attr="disabled"
                >
                    {{ $this->refreshButtonLabel() }}
                </x-filament::button>
            </div>
        </header>
    </section>

    @include('filament-project-passport::components.php2shell-status')

    <div class="fi-pp-dependency-audit__stats {{ $this->showsNpmDependencyResults() ? 'fi-pp-dependency-audit__stats--with-npm' : 'fi-pp-dependency-audit__stats--composer-only' }}">
        <a
            href="{{ $dependencyUrl }}"
            class="fi-pp-card fi-pp-dependency-stat fi-pp-security-audit__link {{ $this->outdatedCount() > 0 ? 'fi-pp-dependency-stat--warn' : 'fi-pp-dependency-stat--ok' }}"
        >
            <p class="fi-pp-dependency-stat__value">{{ $this->outdatedCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                Composer {{ $this->outdatedCount() === 1 ? 'package outdated' : 'packages outdated' }}
            </p>
            <p class="fi-pp-security-audit__cta">Open Dependency Audit →</p>
        </a>

        <a
            href="{{ $dependencyUrl }}"
            class="fi-pp-card fi-pp-dependency-stat fi-pp-security-audit__link {{ $this->advisoryCount() > 0 ? 'fi-pp-dependency-stat--danger' : 'fi-pp-dependency-stat--ok' }}"
        >
            <p class="fi-pp-dependency-stat__value">{{ $this->advisoryCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                Composer {{ $this->advisoryCount() === 1 ? 'security advisory' : 'security advisories' }}
                @if ($this->highAdvisoryCount() > 0)
                    ({{ $this->highAdvisoryCount() }} high/critical)
                @endif
            </p>
            <p class="fi-pp-security-audit__cta">Open Dependency Audit →</p>
        </a>

        @if ($this->showsNpmDependencyResults())
            <a
                href="{{ $dependencyUrl }}"
                class="fi-pp-card fi-pp-dependency-stat fi-pp-security-audit__link {{ $this->npmOutdatedCount() > 0 ? 'fi-pp-dependency-stat--warn' : 'fi-pp-dependency-stat--ok' }}"
            >
                <p class="fi-pp-dependency-stat__value">{{ $this->npmOutdatedCount() }}</p>
                <p class="fi-pp-dependency-stat__label">
                    NPM {{ $this->npmOutdatedCount() === 1 ? 'package outdated' : 'packages outdated' }}
                </p>
                <p class="fi-pp-security-audit__cta">Open Dependency Audit →</p>
            </a>

            <a
                href="{{ $dependencyUrl }}"
                class="fi-pp-card fi-pp-dependency-stat fi-pp-security-audit__link {{ $this->npmAdvisoryCount() > 0 ? 'fi-pp-dependency-stat--danger' : 'fi-pp-dependency-stat--ok' }}"
            >
                <p class="fi-pp-dependency-stat__value">{{ $this->npmAdvisoryCount() }}</p>
                <p class="fi-pp-dependency-stat__label">
                    NPM {{ $this->npmAdvisoryCount() === 1 ? 'security advisory' : 'security advisories' }}
                    @if ($this->npmHighAdvisoryCount() > 0)
                        ({{ $this->npmHighAdvisoryCount() }} high/critical)
                    @endif
                </p>
                <p class="fi-pp-security-audit__cta">Open Dependency Audit →</p>
            </a>
        @endif
    </div>

    @if ($this->showsNpmNodeUnavailableNotice())
        <section class="fi-pp-license-summary fi-pp-license-summary--warn" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-information-circle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">Node.js not available</h3>
                <p class="fi-pp-license-summary__text">
                    {{ $this->npmAudit['message'] ?? \EgyptDevRu\FilamentProjectPassport\Support\NpmRuntime::unavailableMessage() }}
                </p>
            </div>
        </section>
    @endif
</div>
