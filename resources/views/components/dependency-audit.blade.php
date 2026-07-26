<div class="fi-pp-dependency-audit">
    <section class="fi-pp-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Dependency Audit</h3>
            <p class="fi-pp-card__desc">
                Review outdated Composer packages and security advisories reported by
                <code class="fi-pp-code-pill">composer outdated</code>
                and
                <code class="fi-pp-code-pill">composer audit</code>.
            </p>
            @include('filament-project-passport::components.cache-status', [
                'checkedAt' => $this->audit['checked_at'] ?? null,
                'fromCache' => (bool) ($this->audit['from_cache'] ?? false),
                'scheduleHint' => 'Auto-refresh: daily at 03:00 — forced on Sundays, otherwise only when the cache is older than 7 days.',
            ])
        </header>
    </section>

    @if (! empty($this->audit['error']))
        <section class="fi-pp-license-summary fi-pp-license-summary--danger" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">Dependency check unavailable</h3>
                <p class="fi-pp-license-summary__text">
                    Composer could not complete the dependency audit on this server.
                </p>
                <p class="fi-pp-license-summary__text fi-pp-mono">
                    {{ $this->audit['error'] }}
                </p>
            </div>
        </section>
    @endif

    <div class="fi-pp-dependency-audit__stats">
        <section class="fi-pp-card fi-pp-dependency-stat {{ $this->outdatedCount() > 0 ? 'fi-pp-dependency-stat--warn' : 'fi-pp-dependency-stat--ok' }}">
            <p class="fi-pp-dependency-stat__value">{{ $this->outdatedCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                {{ $this->outdatedCount() === 1 ? 'package outdated' : 'packages outdated' }}
            </p>
        </section>

        <section class="fi-pp-card fi-pp-dependency-stat {{ $this->advisoryCount() > 0 ? 'fi-pp-dependency-stat--danger' : 'fi-pp-dependency-stat--ok' }}">
            <p class="fi-pp-dependency-stat__value">{{ $this->advisoryCount() }}</p>
            <p class="fi-pp-dependency-stat__label">
                {{ $this->advisoryCount() === 1 ? 'security advisory' : 'security advisories' }}
            </p>
        </section>
    </div>

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
        <div class="fi-pp-license-audit__toolbar">
            <label class="fi-pp-license-audit__search">
                <span class="sr-only">Search outdated packages</span>
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
                                No outdated packages found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="fi-pp-card fi-pp-license-audit__table-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Security advisories</h3>
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
                            <td>{{ $advisory['cve'] ?: '—' }}</td>
                            <td><span class="fi-pp-mono">{{ $advisory['affected_versions'] ?: '—' }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="fi-pp-license-audit__empty">
                                No security advisories found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
