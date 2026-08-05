<x-filament-panels::page>
    <div
        class="fi-pp"
        wire:init="loadPageData"
        x-data="{
            buffer: '',
            timer: null,
            scanTimer: null,
            pollTimer: null,
            scanTimeoutMs: 3 * 60 * 1000,
            armScanTimeout(scanning) {
                clearTimeout(this.scanTimer)
                this.scanTimer = null

                if (! scanning) {
                    return
                }

                this.scanTimer = setTimeout(async () => {
                    try {
                        await $wire.failScanTimeout()
                    } catch (_) {}
                }, this.scanTimeoutMs)
            },
            startPoll() {
                this.stopPoll()
                this.pollTimer = setInterval(async () => {
                    if (document.visibilityState !== 'visible') {
                        return
                    }

                    if (! $wire.scanning) {
                        this.stopPoll()

                        return
                    }

                    try {
                        await $wire.pollPageData()
                    } catch (_) {
                        // After tab inactivity Livewire may abort; never leave an uncaught rejection.
                    }
                }, 3000)
            },
            stopPoll() {
                clearInterval(this.pollTimer)
                this.pollTimer = null
            },
            async runRefresh() {
                this.armScanTimeout(true)
                this.startPoll()

                try {
                    await $wire.refreshDependencyAudit()
                } catch (_) {}

                this.armScanTimeout(Boolean($wire.scanning))

                if ($wire.scanning) {
                    this.startPoll()
                } else {
                    this.stopPoll()
                }
            },
            onKey(event) {
                const tag = (event.target && event.target.tagName) ? event.target.tagName.toUpperCase() : ''
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || event.target?.isContentEditable) {
                    return
                }

                if (! event.key || event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
                    return
                }

                this.buffer += event.key.toLowerCase()
                if (this.buffer.length > 24) {
                    this.buffer = this.buffer.slice(-24)
                }

                clearTimeout(this.timer)
                this.timer = setTimeout(() => { this.buffer = '' }, 3000)

                if (this.buffer.includes('refresh')) {
                    this.buffer = ''
                    this.runRefresh()
                }
            },
        }"
        x-init="
            armScanTimeout(Boolean($wire.scanning));
            if ($wire.scanning) startPoll();
            $wire.$watch('scanning', value => {
                armScanTimeout(Boolean(value));
                Boolean(value) ? startPoll() : stopPoll();
            });
        "
        x-on:keydown.window="onKey($event)"
        x-on:visibilitychange.window="
            if (document.visibilityState === 'visible' && $wire.scanning) {
                startPoll()
            }
        "
    >
        {{-- Always render the page shell immediately (empty or cached). Never block first paint on Composer. --}}
        @if ($this->scanning && empty($this->audit['error']))
            @include('filament-project-passport::components.loading-state', [
                'title' => 'Updating…',
                'message' => '0 outdated / 0 advisories until the background check finishes. This page reloads the numbers automatically.',
            ])
        @endif

        @include('filament-project-passport::components.dependency-audit')
    </div>
</x-filament-panels::page>
