<div class="fi-pp-oss">
    <section class="fi-pp-card">
        <header class="fi-pp-card__header">
            <h3 class="fi-pp-card__title">Open Source Licenses</h3>
            <p class="fi-pp-card__desc">
                Full license texts for Composer packages installed in this application
                (including transitive dependencies), read from each package’s LICENSE file
                when available.
            </p>
            @include('filament-project-passport::components.cache-status', [
                'checkedAt' => $this->checkedAt,
                'fromCache' => $this->fromCache,
                'scheduleHint' => 'Auto-refresh: daily at 03:00 — only when the cache is missing or older than 14 days.',
            ])
        </header>
    </section>

    <section class="fi-pp-card fi-pp-oss__list-card">
        <div class="fi-pp-license-audit__toolbar">
            <label class="fi-pp-license-audit__search">
                <span class="sr-only">Search packages</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-pp-license-audit__search-icon h-4 w-4" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search packages or licenses…"
                    class="fi-pp-license-audit__search-input"
                />
            </label>
            <p class="fi-pp-muted">
                {{ count($this->filteredPackages) }}
                /
                {{ count($this->packages) }}
                packages
                ·
                {{ $this->packagesWithLicenseTextCount() }} with license text
            </p>
        </div>

        <div class="fi-pp-oss__accordion" role="list">
            @forelse ($this->filteredPackages as $package)
                <details
                    class="fi-pp-oss__item"
                    wire:key="oss-{{ $package['name'] }}"
                    role="listitem"
                >
                    <summary class="fi-pp-oss__summary">
                        <span class="fi-pp-oss__summary-main">
                            <span class="fi-pp-mono fi-pp-oss__name">{{ $package['name'] }}</span>
                            <span class="fi-pp-oss__meta">
                                <span class="fi-pp-oss__version">{{ $package['version'] }}</span>
                                <span class="fi-pp-badge fi-pp-badge--outline">{{ $package['license_label'] }}</span>
                            </span>
                        </span>
                        <span class="fi-pp-oss__chevron" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                        </span>
                    </summary>

                    <div class="fi-pp-oss__panel">
                        @if ($package['has_license_text'])
                            @if (! empty($package['license_file']))
                                <p class="fi-pp-muted fi-pp-oss__file">
                                    Source file: {{ $package['license_file'] }}
                                </p>
                            @endif
                            <pre class="fi-pp-oss__text">{{ $package['license_text'] }}</pre>
                        @else
                            <p class="fi-pp-oss__missing">
                                No LICENSE file was found in this package directory.
                                @if ($package['license_label'] !== 'Unknown')
                                    SPDX / declared license: {{ $package['license_label'] }}.
                                @else
                                    The package did not declare a license in composer.json either.
                                @endif
                            </p>
                        @endif
                    </div>
                </details>
            @empty
                <p class="fi-pp-license-audit__empty">
                    No installed Composer packages matched your search.
                </p>
            @endforelse
        </div>
    </section>
</div>
