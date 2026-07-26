@php
    /** @var array<string, mixed> $license */
    $license = $this->license;
    $supportActive = $this->isSupportActive();
    $verifiedWithoutSupport = $this->isVerifiedWithoutActiveSupport();

    $hasProjectDetails = filled($license['project_name'] ?? null)
        || filled($license['client_name'] ?? null)
        || filled($license['support_warranty_until'] ?? null)
        || filled($license['extended_support_warranty_until'] ?? null);

    $hasDeveloperDetails = filled($license['developer_name'] ?? null)
        || filled($license['email'] ?? null)
        || filled($license['phone'] ?? null);
@endphp

<div class="fi-pp-official {{ $verifiedWithoutSupport ? 'fi-pp-official--warning' : '' }}">
    <div class="fi-pp-official__status">
        @if ($supportActive)
            <span class="fi-pp-badge fi-pp-badge--success">
                <x-filament::icon icon="heroicon-o-shield-check" class="h-4 w-4" />
                Supported by EgyptDev.ru
            </span>
        @else
            <span class="fi-pp-badge fi-pp-badge--warning">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                Not Covered by EgyptDev.ru Support
            </span>
        @endif

        <span class="fi-pp-badge fi-pp-badge--outline-success">
            Domain is verified
        </span>
    </div>

    <section class="fi-pp-status-copy">
        @if ($supportActive)
            <h2 class="fi-pp-status-copy__title">Supported by EgyptDev.ru</h2>
            <p class="fi-pp-status-copy__text">
                This installation is registered with EgyptDev.ru and is covered by an
                active development, maintenance, or support agreement in accordance
                with the applicable contract.
            </p>
        @else
            <h2 class="fi-pp-status-copy__title">Not Covered by EgyptDev.ru Support</h2>
            <p class="fi-pp-status-copy__text">
                This domain is registered with EgyptDev.ru, but no active development,
                maintenance, or support warranty could be verified for the current date.
            </p>
            <p class="fi-pp-status-copy__text">
                This status <strong>does not affect the functionality of the software</strong>
                and does not indicate any issue with Laravel, Filament, or any other
                third-party software or their respective licenses.
            </p>
        @endif
    </section>

    @if ($hasProjectDetails || $hasDeveloperDetails)
        <div class="fi-pp-grid">
            @if ($hasProjectDetails)
                <section class="fi-pp-card">
                    <header class="fi-pp-card__header">
                        <h3 class="fi-pp-card__title">Project Information</h3>
                        <p class="fi-pp-card__desc">Registered project details for this domain.</p>
                    </header>

                    <dl class="fi-pp-dl fi-pp-dl--2">
                        @if (filled($license['project_name'] ?? null))
                            <div>
                                <dt>Project name</dt>
                                <dd>{{ $license['project_name'] }}</dd>
                            </div>
                        @endif
                        @if (filled($license['client_name'] ?? null))
                            <div>
                                <dt>Client name</dt>
                                <dd>{{ $license['client_name'] }}</dd>
                            </div>
                        @endif
                        @if (filled($license['support_warranty_until'] ?? null))
                            <div>
                                <dt>Support warranty until</dt>
                                <dd>{{ $license['support_warranty_until'] }}</dd>
                            </div>
                        @endif
                        @if (filled($license['extended_support_warranty_until'] ?? null))
                            <div>
                                <dt>Extended support warranty until</dt>
                                <dd>{{ $license['extended_support_warranty_until'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif

            @if ($hasDeveloperDetails)
                <section class="fi-pp-card">
                    <header class="fi-pp-card__header">
                        <h3 class="fi-pp-card__title">Agency / Developer</h3>
                        <p class="fi-pp-card__desc">Contact your development partner for support.</p>
                    </header>

                    <dl class="fi-pp-dl">
                        @if (filled($license['developer_name'] ?? null))
                            <div>
                                <dt>Developer name</dt>
                                <dd>{{ $license['developer_name'] }}</dd>
                            </div>
                        @endif
                        @if (filled($license['email'] ?? null) && filter_var((string) $license['email'], FILTER_VALIDATE_EMAIL))
                            <div>
                                <dt>Email</dt>
                                <dd>
                                    <a href="mailto:{{ $license['email'] }}">{{ $license['email'] }}</a>
                                </dd>
                            </div>
                        @elseif (filled($license['email'] ?? null))
                            <div>
                                <dt>Email</dt>
                                <dd>{{ $license['email'] }}</dd>
                            </div>
                        @endif
                        @if (filled($license['phone'] ?? null))
                            <div>
                                <dt>Phone</dt>
                                <dd>
                                    @php
                                        $phoneDisplay = (string) $license['phone'];
                                        $phoneHref = preg_replace('/[^\d+]/', '', $phoneDisplay) ?? '';
                                    @endphp
                                    @if ($phoneHref !== '' && preg_match('/^\+?\d{5,20}$/', $phoneHref) === 1)
                                        <a href="tel:{{ $phoneHref }}">{{ $phoneDisplay }}</a>
                                    @else
                                        {{ $phoneDisplay }}
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif
        </div>
    @endif
</div>
