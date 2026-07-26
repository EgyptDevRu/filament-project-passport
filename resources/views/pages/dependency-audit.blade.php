<x-filament-panels::page>
    <div
        class="fi-pp"
        wire:init="loadPageData"
        @if ($this->scanning)
            wire:poll.3s="pollPageData"
        @endif
        x-data="{
            buffer: '',
            timer: null,
            scanTimer: null,
            scanTimeoutMs: 3 * 60 * 1000,
            armScanTimeout(scanning) {
                clearTimeout(this.scanTimer)
                this.scanTimer = null

                if (! scanning) {
                    return
                }

                this.scanTimer = setTimeout(() => {
                    $wire.failScanTimeout()
                }, this.scanTimeoutMs)
            },
            async runRefresh() {
                this.armScanTimeout(true)
                await $wire.refreshDependencyAudit()
                this.armScanTimeout(Boolean($wire.scanning))
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
            $wire.$watch('scanning', value => armScanTimeout(Boolean(value)));
        "
        x-on:keydown.window="onKey($event)"
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
