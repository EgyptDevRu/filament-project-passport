<x-filament-panels::page>
    <div
        class="fi-pp"
        x-data="{
            buffer: '',
            timer: null,
            open: false,
            logJson: '',
            async showDeveloperLog() {
                try {
                    const log = await $wire.getDeveloperLog()
                    this.logJson = JSON.stringify(log, null, 2)
                    this.open = true
                } catch (e) {
                    this.logJson = JSON.stringify({ error: String(e) }, null, 2)
                    this.open = true
                }
            },
            async runRefresh() {
                await $wire.refreshLicenseCheck()
            },
            onKey(event) {
                if (this.open) {
                    return
                }

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

                if (this.buffer.includes('egyptdev')) {
                    this.buffer = ''
                    this.showDeveloperLog()
                    return
                }

                if (this.buffer.includes('refresh')) {
                    this.buffer = ''
                    this.runRefresh()
                }
            },
        }"
        x-on:keydown.window="onKey($event)"
    >
        @if ($this->isUndefinedStatus())
            @include('filament-project-passport::components.undefined-status')
        @elseif ($this->isOfficial())
            @include('filament-project-passport::components.developer-info')
        @else
            @include('filament-project-passport::components.unofficial-alert')
        @endif

        @include('filament-project-passport::components.support-intro')

        <template x-teleport="body">
            <div
                class="fi-pp-debug-overlay"
                x-show="open"
                x-cloak
                x-transition.opacity
                x-on:keydown.escape.window="open = false"
                style="display: none;"
            >
                <div
                    class="fi-pp-debug-modal"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Developer license log"
                    x-on:click.outside="open = false"
                >
                    <div class="fi-pp-debug-modal__header">
                        <h3>Developer license log</h3>
                        <button type="button" class="fi-pp-debug-modal__close" x-on:click="open = false">
                            Close
                        </button>
                    </div>
                    <pre class="fi-pp-debug__pre"><code x-text="logJson"></code></pre>
                </div>
            </div>
        </template>
    </div>
</x-filament-panels::page>
