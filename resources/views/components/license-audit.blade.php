<div class="fi-pp-license-audit">
    <section class="fi-pp-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">License Audit</h3>
            <p class="fi-pp-card__desc">
                Review the licenses of all Composer packages installed in this application
                (including transitive dependencies) and verify their compatibility with
                commercial software distribution.
            </p>
            @include('filament-project-passport::components.cache-status', [
                'checkedAt' => $this->checkedAt,
                'fromCache' => $this->fromCache,
                'scheduleHint' => 'Auto-refresh: daily at 03:00 — only when the cache is missing or older than 14 days.',
            ])
        </header>
    </section>

    <section class="fi-pp-card fi-pp-license-audit__table-card">
        <div class="fi-pp-license-audit__toolbar">
            <label class="fi-pp-license-audit__search">
                <span class="sr-only">Search packages</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-pp-license-audit__search-icon h-4 w-4" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search packages, licenses, status…"
                    class="fi-pp-license-audit__search-input"
                />
            </label>
            <p class="fi-pp-muted">
                {{ count($this->filteredPackages) }}
                /
                {{ count($this->packages) }}
                installed packages
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
                                Version
                                @if ($sortColumn === 'version')
                                    <span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('license_label')" class="fi-pp-license-audit__sort">
                                License
                                @if ($sortColumn === 'license_label')
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
                    @forelse ($this->filteredPackages as $package)
                        <tr wire:key="pkg-{{ $package['name'] }}">
                            <td>
                                <span class="fi-pp-mono">{{ $package['name'] }}</span>
                            </td>
                            <td>{{ $package['version'] }}</td>
                            <td>{{ $package['license_label'] }}</td>
                            <td>
                                <span class="{{ $this->statusBadgeClass($package['status']) }}">
                                    {{ $package['status_label'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="fi-pp-license-audit__empty">
                No installed Composer packages matched your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($this->hasIncompatiblePackages())
        <section class="fi-pp-license-summary fi-pp-license-summary--danger" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">Potential License Conflict Detected</h3>
                <p class="fi-pp-license-summary__text">
                    One or more installed packages use licenses that may be incompatible
                    with proprietary commercial software.
                </p>
                <p class="fi-pp-license-summary__text">
                    Review the packages listed below before distributing this application.
                </p>

                <div class="fi-pp-license-audit__table-wrap fi-pp-license-audit__table-wrap--nested">
                    <table class="fi-pp-license-audit__table">
                        <thead>
                            <tr>
                                <th>Package</th>
                                <th>Version</th>
                                <th>License</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->incompatiblePackages as $package)
                                <tr wire:key="incompat-{{ $package['name'] }}">
                                    <td><span class="fi-pp-mono">{{ $package['name'] }}</span></td>
                                    <td>{{ $package['version'] }}</td>
                                    <td>{{ $package['license_label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @else
        <section class="fi-pp-license-summary fi-pp-license-summary--success" role="status">
            <div class="fi-pp-license-summary__icon">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-7 w-7" />
            </div>
            <div class="fi-pp-license-summary__body">
                <h3 class="fi-pp-license-summary__title">Commercial License Check Passed</h3>
                <p class="fi-pp-license-summary__text">
                    No incompatible package licenses were detected.
                </p>
                <p class="fi-pp-license-summary__text">
                    Based on the installed Composer packages, this application may be
                    commercially distributed.
                </p>
                <p class="fi-pp-license-summary__text">
                    Packages marked as “Requires Review” should be reviewed manually
                    before distribution.
                </p>
            </div>
        </section>
    @endif
</div>
