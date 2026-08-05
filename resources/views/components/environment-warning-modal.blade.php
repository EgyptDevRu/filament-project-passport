@if (\EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning::isPending())
    @php
        $env = \EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning::environmentLabel();
        $title = \EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning::title();
        $message = \EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning::message();
        $domain = \EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning::installationDomain();
        $dismissUrl = route('filament-project-passport.dismiss-environment-warning', absolute: false);
    @endphp

    <div
        x-data="{
            open: true,
            dismissing: false,
            async dismiss() {
                if (this.dismissing) {
                    return
                }

                this.dismissing = true

                const csrf = document.querySelector('meta[name=csrf-token]')?.getAttribute('content')
                    || document.querySelector('input[name=_token]')?.value
                    || ''

                try {
                    await fetch(@js($dismissUrl), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({}),
                    })
                } catch (_) {
                    // Still close locally if the dismiss request fails.
                }

                this.open = false
            },
        }"
        x-show="open"
        x-cloak
        class="fi-pp-env-warning"
        style="display: none;"
    >
        <div
            class="fi-pp-debug-overlay"
            x-transition.opacity
            role="presentation"
            x-on:click.stop
        >
            <div
                class="fi-pp-env-warning__modal"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="fi-pp-env-warning-title"
                aria-describedby="fi-pp-env-warning-body"
                x-on:click.stop
            >
                <div class="fi-pp-env-warning__icon">
                    <x-filament::icon
                        icon="heroicon-o-exclamation-triangle"
                        class="fi-pp-env-warning__icon-svg"
                        aria-hidden="true"
                    />
                    <span class="fi-pp-env-warning__badge">{{ $env }}</span>
                </div>

                <h3 id="fi-pp-env-warning-title" class="fi-pp-env-warning__title">
                    {{ $title }}
                </h3>

                <p id="fi-pp-env-warning-body" class="fi-pp-env-warning__text">
                    {{ $message }}
                </p>

                <div class="fi-pp-env-warning__actions">
                    <button
                        type="button"
                        class="fi-pp-env-warning__button"
                        x-on:click="dismiss()"
                        x-bind:disabled="dismissing"
                    >
                        I understand
                    </button>
                </div>

                @if ($domain !== '')
                    <p class="fi-pp-env-warning__installation">
                        Installation: {{ $domain }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif
