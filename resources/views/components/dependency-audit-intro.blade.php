@php
    $contact = $this->studioContact();
@endphp

<section
    class="fi-pp-intro"
    aria-label="About this dependency audit"
    x-data="{ open: false }"
>
    <button
        type="button"
        class="fi-pp-intro__toggle"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open.toString()"
    >
        <span>About this dependency audit</span>
        <span
            class="fi-pp-intro__chevron"
            x-bind:class="{ 'fi-pp-intro__chevron--open': open }"
            aria-hidden="true"
        >
            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
        </span>
    </button>

    <div
        class="fi-pp-intro__body"
        x-show="open"
        style="display: none;"
    >
        <p class="fi-pp-intro__text">
            Dependency results are <strong>cached and refreshed automatically every
            {{ \EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor::CACHE_FRESH_DAYS }} days</strong>
            (daily schedule at 03:00 — forced on Sundays, otherwise only when the cache is missing or older than
            {{ \EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor::CACHE_FRESH_DAYS }} days).
            This page is informational: it helps you monitor the current state and confirm that dependency health looks as expected.
        </p>

        <p class="fi-pp-intro__text">
            If you see <strong>high-risk security advisories</strong> that are still present after a successful
            last check that is more than
            {{ \EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor::CACHE_FRESH_DAYS }} days old,
            please contact EgyptDev Studio by email
            @if (filled($contact['email']) && filter_var((string) $contact['email'], FILTER_VALIDATE_EMAIL))
                (<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>)
            @else
                (your Studio contact email)
            @endif
            or by the developer’s direct phone number
            @if (filled($contact['phone']))
                ({{ $contact['phone'] }})
            @endif
            so we can confirm there is no unexpected production risk.
        </p>

        <p class="fi-pp-intro__text">
            Security advisories listed here come from <strong>external open-source packages</strong>.
            EgyptDev Studio contributes to the open-source community and often helps investigate and fix
            security issues and bugs with independent package maintainers on a <strong>pro bono</strong> basis.
            We are <strong>not responsible</strong> for third-party packages or for timelines set by those
            maintainers. Fixes, backports, and coordinated disclosures may be delayed by upstream developers,
            or may require substantial analysis, patching, testing, and rollout time.
        </p>

        <p class="fi-pp-intro__text">
            Each confirmed <strong>high-severity</strong> advisory is reviewed carefully and addressed as soon
            as practical. <strong>Low-severity</strong> findings are often limited to development dependencies
            (used only in non-production environments) and may not present a real production risk; in those
            cases a fix may be scheduled later.
        </p>
    </div>
</section>
