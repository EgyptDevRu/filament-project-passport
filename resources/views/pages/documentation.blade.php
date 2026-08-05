<x-filament-panels::page>
    <div
        class="fi-pp"
        wire:init="loadPageData"
        x-data="{
            pollTimer: null,
            startLicensePoll() {
                this.stopLicensePoll()
                this.pollTimer = setInterval(async () => {
                    if (document.visibilityState !== 'visible') {
                        return
                    }

                    try {
                        await $wire.pollLicenseStatus()
                    } catch (_) {
                        // Tab resume / aborted Livewire requests must not surface as uncaught promises.
                    }

                    if (! $wire.licensePending) {
                        this.stopLicensePoll()
                    }
                }, 3000)
            },
            stopLicensePoll() {
                clearInterval(this.pollTimer)
                this.pollTimer = null
            },
        }"
        x-init="
            if ($wire.licensePending) {
                startLicensePoll()
            }
            $wire.$watch('licensePending', (pending) => {
                pending ? startLicensePoll() : stopLicensePoll()
            })
        "
    >
        @if ($this->licensePending)
            @include('filament-project-passport::components.loading-state', [
                'title' => 'Verifying license…',
                'message' => 'Documentation unlocks automatically once this domain is verified. You do not need to open Status first.',
            ])
        @elseif (! $this->licenseAllowsContent)
            @include('filament-project-passport::components.docs-license-placeholder')
        @elseif (! $this->documentationContentAllowed())
            @include('filament-project-passport::components.docs-environment-placeholder')
        @elseif (! $this->ready)
            @include('filament-project-passport::components.loading-state', [
                'title' => 'Loading documentation…',
                'message' => 'Scanning project docs. This usually finishes in a few seconds.',
            ])
        @else
            @include('filament-project-passport::components.docs-viewer')
        @endif
    </div>
</x-filament-panels::page>
