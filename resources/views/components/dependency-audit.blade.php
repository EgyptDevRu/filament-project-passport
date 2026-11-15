<div class="fi-pp-dependency-audit">
    @include('filament-project-passport::components.dependency-audit-intro')

    <section class="fi-pp-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Dependency Audit</h3>
            <p class="fi-pp-card__desc">
                Review outdated Composer packages and security advisories reported by
                <code class="fi-pp-code-pill">composer outdated</code>
                and
                <code class="fi-pp-code-pill">composer audit</code>.
                @if ($this->showsNpmDependencyResults())
                    When Node.js is available, frontend packages are also checked with
                    <code class="fi-pp-code-pill">npm outdated</code>
                    and
                    <code class="fi-pp-code-pill">npm audit</code>.
                @endif
            </p>
            @include('filament-project-passport::components.cache-status', [
                'checkedAt' => $this->audit['checked_at'] ?? null,
                'fromCache' => (bool) ($this->audit['from_cache'] ?? false),
                'scheduleHint' => 'Auto-refresh: daily at 03:00 — forced on Sundays, otherwise only when the cache is older than 7 days.',
            ])
        </header>
    </section>

    @if ($this->shouldEscalateHighAdvisories())
        <section class="fi-pp-license-summary fi-pp-license-summary--danger" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">High-risk advisories still open</h3>
                <p class="fi-pp-license-summary__text">
                    High or critical security advisories are still present more than 7 days after the last
                    successful dependency check. Please contact EgyptDev Studio so production risk can be reviewed.
                </p>
                @php $contact = $this->studioContact(); @endphp
                <p class="fi-pp-license-summary__text">
                    @if (filled($contact['email']) && filter_var((string) $contact['email'], FILTER_VALIDATE_EMAIL))
                        Email:
                        <a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>
                    @endif
                    @if (filled($contact['phone']))
                        · Phone: {{ $contact['phone'] }}
                    @endif
                </p>
            </div>
        </section>
    @endif

    @if (! empty($this->audit['error']))
        <section class="fi-pp-license-summary fi-pp-license-summary--danger" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">Composer dependency check unavailable</h3>
                <p class="fi-pp-license-summary__text">
                    Composer could not complete the dependency audit on this server.
                </p>
                <p class="fi-pp-license-summary__text fi-pp-mono">
                    {{ $this->audit['error'] }}
                </p>
            </div>
        </section>
    @endif

    <div class="fi-pp-dependency-audit__stats {{ $this->showsNpmDependencyResults() ? 'fi-pp-dependency-audit__stats--with-npm' : 'fi-pp-dependency-audit__stats--composer-only' }}">
        <section class="fi-pp-card fi-pp-dependency-stat {{ $this->outdatedCount() > 0 ? 'fi-pp-dependency-stat--warn' : 'fi-pp-dependency-stat--ok' }}">
            <p class="fi-pp-dependency-stat__value">{{ $this->outdatedCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                Composer {{ $this->outdatedCount() === 1 ? 'package outdated' : 'packages outdated' }}
            </p>
        </section>

        <section class="fi-pp-card fi-pp-dependency-stat {{ $this->advisoryCount() > 0 ? 'fi-pp-dependency-stat--danger' : 'fi-pp-dependency-stat--ok' }}">
            <p class="fi-pp-dependency-stat__value">{{ $this->advisoryCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                Composer {{ $this->advisoryCount() === 1 ? 'security advisory' : 'security advisories' }}
                @if ($this->highAdvisoryCount() > 0)
                    ({{ $this->highAdvisoryCount() }} high/critical)
                @endif
            </p>
        </section>

        @if ($this->showsNpmDependencyResults())
            <section class="fi-pp-card fi-pp-dependency-stat {{ $this->npmOutdatedCount() > 0 ? 'fi-pp-dependency-stat--warn' : 'fi-pp-dependency-stat--ok' }}">
                <p class="fi-pp-dependency-stat__value">{{ $this->npmOutdatedCount() }}</p>
                <p class="fi-pp-dependency-stat__label">
                    NPM {{ $this->npmOutdatedCount() === 1 ? 'package outdated' : 'packages outdated' }}
                </p>
            </section>

            <section class="fi-pp-card fi-pp-dependency-stat {{ $this->npmAdvisoryCount() > 0 ? 'fi-pp-dependency-stat--danger' : 'fi-pp-dependency-stat--ok' }}">
                <p class="fi-pp-dependency-stat__value">{{ $this->npmAdvisoryCount() }}</p>
                <p class="fi-pp-dependency-stat__label">
                    NPM {{ $this->npmAdvisoryCount() === 1 ? 'security advisory' : 'security advisories' }}
                    @if ($this->npmHighAdvisoryCount() > 0)
                        ({{ $this->npmHighAdvisoryCount() }} high/critical)
                    @endif
                </p>
            </section>
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

    <div class="fi-pp-dependency-audit__frameworks">
        @php
            $laravel = $this->laravelStatus();
            $filament = $this->filamentStatus();
        @endphp

        <section class="fi-pp-card">
            <header class="fi-pp-card__header">
                <h3 class="fi-pp-card__title">Laravel</h3>
                <p class="fi-pp-card__desc"><code class="fi-pp-mono">laravel/framework</code></p>
            </header>
            @if (! $laravel['installed_flag'])
                <p class="fi-pp-muted">Not installed in this application.</p>
            @elseif ($laravel['up_to_date'])
                <p class="fi-pp-dependency-framework__ok">
                    Up to date
                    <span class="fi-pp-mono">({{ $laravel['installed'] }})</span>
                </p>
            @else
                <p class="fi-pp-dependency-framework__warn">
                    Update available:
                    <span class="fi-pp-mono">{{ $laravel['installed'] }}</span>
                    →
                    <span class="fi-pp-mono">{{ $laravel['latest'] }}</span>
                </p>
            @endif
        </section>

        <section class="fi-pp-card">
            <header class="fi-pp-card__header">
                <h3 class="fi-pp-card__title">Filament</h3>
                <p class="fi-pp-card__desc"><code class="fi-pp-mono">filament/filament</code></p>
            </header>
            @if (! $filament['installed_flag'])
                <p class="fi-pp-muted">Not installed in this application.</p>
            @elseif ($filament['up_to_date'])
                <p class="fi-pp-dependency-framework__ok">
                    Up to date
                    <span class="fi-pp-mono">({{ $filament['installed'] }})</span>
                </p>
            @else
                <p class="fi-pp-dependency-framework__warn">
                    Update available:
                    <span class="fi-pp-mono">{{ $filament['installed'] }}</span>
                    →
                    <span class="fi-pp-mono">{{ $filament['latest'] }}</span>
                </p>
            @endif
        </section>
    </div>

    <section class="fi-pp-card fi-pp-license-audit__table-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Composer outdated packages</h3>
        </header>

        <div class="fi-pp-license-audit__toolbar">
            <label class="fi-pp-license-audit__search">
                <span class="sr-only">Search outdated Composer packages</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-pp-license-audit__search-icon h-4 w-4" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search outdated packages…"
                    class="fi-pp-license-audit__search-input"
                />
            </label>
            <p class="fi-pp-muted">
                {{ count($this->filteredOutdated) }}
                /
                {{ $this->outdatedCount() }}
                outdated
            </p>
        </div>

        <div class="fi-pp-license-audit__table-wrap">
            <table class="fi-pp-license-audit__table">
                <thead>
                    <tr>
                        <th>
                            <button type="button" wire:click="sortBy('name')" class="fi-pp-license-audit__sort">
                                Package
                                @if ($sortColumn === 'name')
                                    <span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('version')" class="fi-pp-license-audit__sort">
                                Installed
                                @if ($sortColumn === 'version')
                                    <span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('latest')" class="fi-pp-license-audit__sort">
                                Latest
                                @if ($sortColumn === 'latest')
                                    <span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('status')" class="fi-pp-license-audit__sort">
                                Status
                                @if ($sortColumn === 'status')
                                    <span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->filteredOutdated as $package)
                        <tr wire:key="outdated-{{ $package['name'] }}">
                            <td><span class="fi-pp-mono">{{ $package['name'] }}</span></td>
                            <td>{{ $package['version'] }}</td>
                            <td>{{ $package['latest'] }}</td>
                            <td>
                                <span class="fi-pp-badge fi-pp-badge--warning">
                                    {{ str_replace('-', ' ', (string) $package['status']) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="fi-pp-license-audit__empty">
                                No outdated Composer packages found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="fi-pp-card fi-pp-license-audit__table-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Composer security advisories</h3>
            <p class="fi-pp-card__desc">
                Issues reported by
                <code class="fi-pp-code-pill">composer audit</code>.
            </p>
        </header>

        <div class="fi-pp-license-audit__table-wrap">
            <table class="fi-pp-license-audit__table">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th>Advisory</th>
                        <th>Severity</th>
                        <th>CVE</th>
                        <th>Affected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->advisories as $advisory)
                        <tr wire:key="adv-{{ md5(($advisory['package'] ?? '').($advisory['title'] ?? '').($advisory['cve'] ?? '')) }}">
                            <td><span class="fi-pp-mono">{{ $advisory['package'] }}</span></td>
                            <td>
                                @if (! empty($advisory['link']))
                                    <a href="{{ $advisory['link'] }}" target="_blank" rel="noopener noreferrer">
                                        {{ $advisory['title'] }}
                                    </a>
                                @else
                                    {{ $advisory['title'] }}
                                @endif
                            </td>
                            <td>
                                <span class="fi-pp-badge {{ in_array(strtolower((string) ($advisory['severity'] ?? '')), ['critical', 'high'], true) ? 'fi-pp-badge--danger' : 'fi-pp-badge--warning' }}">
                                    {{ $advisory['severity'] ?? 'unknown' }}
                                </span>
                            </td>
                            <td>{{ $advisory['cve'] ?: '—' }}</td>
                            <td><span class="fi-pp-mono">{{ $advisory['affected_versions'] ?: '—' }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="fi-pp-license-audit__empty">
                                No Composer security advisories found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($this->showsNpmDependencyResults())
        <section class="fi-pp-card fi-pp-license-audit__table-card">
            <header class="fi-pp-card__header">
                <h3 class="fi-pp-card__title">NPM outdated packages</h3>
                <p class="fi-pp-card__desc">
                    Frontend dependencies from
                    <code class="fi-pp-code-pill">npm outdated</code>.
                </p>
                @include('filament-project-passport::components.cache-status', [
                    'checkedAt' => $this->npmAudit['checked_at'] ?? null,
                    'fromCache' => (bool) ($this->npmAudit['from_cache'] ?? false),
                    'scheduleHint' => 'Auto-refresh: daily at 03:00 — forced on Sundays, otherwise only when the cache is older than 7 days.',
                ])
            </header>

            @if (! empty($this->npmAudit['error']))
                <p class="fi-pp-license-summary__text fi-pp-mono">{{ $this->npmAudit['error'] }}</p>
            @else
                <div class="fi-pp-license-audit__toolbar">
                    <label class="fi-pp-license-audit__search">
                        <span class="sr-only">Search outdated NPM packages</span>
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-pp-license-audit__search-icon h-4 w-4" />
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="npmSearch"
                            placeholder="Search outdated packages…"
                            class="fi-pp-license-audit__search-input"
                        />
                    </label>
                    <p class="fi-pp-muted">
                        {{ count($this->filteredNpmOutdated) }}
                        /
                        {{ $this->npmOutdatedCount() }}
                        outdated
                    </p>
                </div>

                <div class="fi-pp-license-audit__table-wrap">
                    <table class="fi-pp-license-audit__table">
                        <thead>
                            <tr>
                                <th>
                                    <button type="button" wire:click="sortNpmBy('name')" class="fi-pp-license-audit__sort">
                                        Package
                                        @if ($npmSortColumn === 'name')
                                            <span aria-hidden="true">{{ $npmSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                                <th>
                                    <button type="button" wire:click="sortNpmBy('version')" class="fi-pp-license-audit__sort">
                                        Installed
                                        @if ($npmSortColumn === 'version')
                                            <span aria-hidden="true">{{ $npmSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                                <th>
                                    <button type="button" wire:click="sortNpmBy('latest')" class="fi-pp-license-audit__sort">
                                        Latest
                                        @if ($npmSortColumn === 'latest')
                                            <span aria-hidden="true">{{ $npmSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                                <th>
                                    <button type="button" wire:click="sortNpmBy('status')" class="fi-pp-license-audit__sort">
                                        Status
                                        @if ($npmSortColumn === 'status')
                                            <span aria-hidden="true">{{ $npmSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->filteredNpmOutdated as $package)
                                <tr wire:key="npm-outdated-{{ $package['name'] }}">
                                    <td><span class="fi-pp-mono">{{ $package['name'] }}</span></td>
                                    <td>{{ $package['version'] }}</td>
                                    <td>{{ $package['latest'] }}</td>
                                    <td>
                                        <span class="fi-pp-badge fi-pp-badge--warning">
                                            {{ str_replace('-', ' ', (string) $package['status']) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="fi-pp-license-audit__empty">
                                        No outdated NPM packages found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="fi-pp-card fi-pp-license-audit__table-card">
            <header class="fi-pp-card__header">
                <h3 class="fi-pp-card__title">NPM security advisories</h3>
                <p class="fi-pp-card__desc">
                    Issues reported by
                    <code class="fi-pp-code-pill">npm audit</code>.
                </p>
            </header>

            @if (! empty($this->npmAudit['error']))
                <p class="fi-pp-license-summary__text fi-pp-mono">{{ $this->npmAudit['error'] }}</p>
            @else
                <div class="fi-pp-license-audit__table-wrap">
                    <table class="fi-pp-license-audit__table">
                        <thead>
                            <tr>
                                <th>Package</th>
                                <th>Advisory</th>
                                <th>Severity</th>
                                <th>CVE</th>
                                <th>Affected</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->npmAdvisories as $advisory)
                                <tr wire:key="npm-adv-{{ md5(($advisory['package'] ?? '').($advisory['title'] ?? '').($advisory['cve'] ?? '')) }}">
                                    <td><span class="fi-pp-mono">{{ $advisory['package'] }}</span></td>
                                    <td>
                                        @if (! empty($advisory['link']))
                                            <a href="{{ $advisory['link'] }}" target="_blank" rel="noopener noreferrer">
                                                {{ $advisory['title'] }}
                                            </a>
                                        @else
                                            {{ $advisory['title'] }}
                                        @endif
                                    </td>
                                    <td>
                                        <span class="fi-pp-badge {{ in_array(strtolower((string) ($advisory['severity'] ?? '')), ['critical', 'high'], true) ? 'fi-pp-badge--danger' : 'fi-pp-badge--warning' }}">
                                            {{ $advisory['severity'] ?? 'unknown' }}
                                        </span>
                                    </td>
                                    <td>{{ $advisory['cve'] ?: '—' }}</td>
                                    <td><span class="fi-pp-mono">{{ $advisory['affected_versions'] ?: '—' }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="fi-pp-license-audit__empty">
                                        No NPM security advisories found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
