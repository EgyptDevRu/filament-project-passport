@php
    $collapsedByDefault = $this->isOfficial();
@endphp

<section
    class="fi-pp-intro"
    aria-label="About this support status"
    x-data="{ open: {{ $collapsedByDefault ? 'false' : 'true' }} }"
>
    <button
        type="button"
        class="fi-pp-intro__toggle"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open.toString()"
    >
        <span>About this support status</span>
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
        @if ($collapsedByDefault)
            style="display: none;"
        @endif
    >
        <p class="fi-pp-intro__text">
            This page displays the support status of this software installation
            <strong>provided by EgyptDev.ru</strong>.
        </p>

        <p class="fi-pp-intro__text">
            The information shown here relates <strong>only</strong> to the software
            developed and maintained by EgyptDev.ru and the contractual support services
            associated with it.
        </p>

        <p class="fi-pp-intro__text">
            This status <strong>does not</strong> represent, replace, modify, or verify
            the licensing or support status of Laravel, Filament, or any other
            third-party software. Laravel, Filament, and other third-party components
            remain subject to their own licenses and terms.
        </p>
    </div>
</section>
