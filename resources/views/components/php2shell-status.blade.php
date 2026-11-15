@php
    $php = $this->phpRuntimeStatus();
    $runtime = $php['runtime'];
    $binaries = $php['binaries'] ?? [];
    $cardTone = $php['is_secure']
        ? 'fi-pp-php2shell--ok'
        : (
            in_array($runtime['status'], ['critical', 'outdated'], true) || ($php['has_critical'] ?? false)
                ? 'fi-pp-php2shell--danger'
                : 'fi-pp-php2shell--warn'
        );
@endphp

<section class="fi-pp-card fi-pp-php2shell {{ $cardTone }}" role="status">
    <header class="fi-pp-card__header">
        <h3 class="fi-pp-card__title">PHP2SHELL Status</h3>
        <p class="fi-pp-card__desc">
            Runtime PHP health for the server running this application.
            Security floor: builds at or above 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12
            (21 Oct 2026 security release, after 14:00 UTC).
            Other host PHP binaries are cached for 7 days and refreshed with Security Audit
            (or the daily Dependency Audit schedule).
        </p>
        @include('filament-project-passport::components.cache-status', [
            'checkedAt' => $php['checked_at'] ?? null,
            'fromCache' => (bool) ($php['from_cache'] ?? false),
            'scheduleHint' => 'Host PHP binary scan: daily with Dependency Audit when older than 7 days; also on Security Audit Refresh.',
        ])
    </header>

    @if ($runtime['status'] === 'ok')
        <div class="fi-pp-php2shell__runtime fi-pp-php2shell__runtime--ok">
            <p>Runtime PHP is up to date: <span class="fi-pp-mono">{{ $runtime['version'] }}</span></p>
            <p>{{ $runtime['label'] }}</p>
            @if (filled($runtime['build_date'] ?? null))
                <p>Build date: <span class="fi-pp-mono">{{ $runtime['build_date'] }}</span></p>
            @endif
        </div>
    @elseif ($runtime['status'] === 'suspect')
        <div class="fi-pp-php2shell__runtime fi-pp-php2shell__runtime--warn">
            <p>Possible security problem: <span class="fi-pp-mono">{{ $runtime['version'] }}</span></p>
            <p>{{ $runtime['label'] }}</p>
            @if (filled($runtime['build_date'] ?? null))
                <p>Build date: <span class="fi-pp-mono">{{ $runtime['build_date'] }}</span></p>
            @endif
            @if (! empty($runtime['minimum_secure']))
                <p>Minimum secure build for this branch: <span class="fi-pp-mono">{{ $runtime['minimum_secure'] }}</span></p>
            @endif
            <p>Verify that this binary includes the 21 Oct 2026 fixes, or start an <strong>emergency PHP update</strong>.</p>
        </div>
    @else
        <div class="fi-pp-php2shell__runtime fi-pp-php2shell__runtime--danger">
            <p>Runtime PHP is outdated: <span class="fi-pp-mono">{{ $runtime['version'] }}</span></p>
            <p>{{ $runtime['label'] }}</p>
            @if (filled($runtime['build_date'] ?? null))
                <p>Build date: <span class="fi-pp-mono">{{ $runtime['build_date'] }}</span></p>
            @endif
            @if (! empty($runtime['minimum_secure']))
                <p>Minimum secure build for this branch: <span class="fi-pp-mono">{{ $runtime['minimum_secure'] }}</span></p>
            @endif
            <p>Start an <strong>emergency PHP update</strong> for this server.</p>
        </div>
    @endif

    @if ($binaries !== [])
        <div class="fi-pp-php2shell__binaries">
            <p class="fi-pp-muted">Other PHP binaries detected on this host:</p>
            <ul class="fi-pp-php2shell__list">
                @foreach ($binaries as $binary)
                    <li class="fi-pp-php2shell__item fi-pp-php2shell__item--{{ $binary['status'] }}">
                        <span class="fi-pp-mono">{{ $binary['path'] }}</span>
                        —
                        <span class="fi-pp-mono">{{ $binary['version'] }}</span>
                        ({{ $binary['label'] }})
                        @if (filled($binary['build_date'] ?? null))
                            · Build
                            <span class="fi-pp-mono">{{ $binary['build_date'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($php['has_critical'])
        <p class="fi-pp-php2shell__alert">
            Remove all unsupported PHP major versions (PHP 4, 5, 6, 7, and other end-of-life builds)
            from this server as soon as possible — even for local environments without internet access.
            Unpatched PHP binaries remain a webshell / remote-code-execution risk if the host is later exposed.
        </p>
    @endif

    @if ($php['has_suspect'] ?? false)
        <p class="fi-pp-php2shell__alert fi-pp-php2shell__alert--warn">
            At least one PHP binary was compiled after the 21 Oct 2026 14:00 UTC security release window
            but still reports a version below the minimum secure patch. This can happen with custom or
            distro rebuilds that never bumped the version string — treat it as a possible security problem
            until the binary is confirmed patched or upgraded.
        </p>
    @endif

    <p class="fi-pp-intro__text">
        {{ $php['cloudflare_note'] }}
    </p>
</section>
