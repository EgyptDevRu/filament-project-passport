<x-filament-panels::page>
    <div
        class="fi-pp"
        dir="ltr"
        wire:init="loadPageData"
        x-data="{
            buffer: '',
            timer: null,
            async runRefresh() {
                try {
                    await $wire.refreshOpenSourceLicenses()
                } catch (_) {}
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
        x-on:keydown.window="onKey($event)"
    >
        @if (! $this->ready)
            @include('filament-project-passport::components.loading-state', [
                'title' => 'Loading open source licenses…',
                'message' => 'Reading Composer package license files. This may take a moment on the first visit.',
            ])
        @else
            @include('filament-project-passport::components.open-source-licenses')
        @endif
    </div>
</x-filament-panels::page>
