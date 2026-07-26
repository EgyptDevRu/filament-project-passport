<div class="fi-pp-docs">
    @if (count($this->documents) === 0)
        <section class="fi-pp-card">
            <header class="fi-pp-card__header">
                <h3 class="fi-pp-card__title">Documentation</h3>
                <p class="fi-pp-card__desc">
                    Local project documentation from the <code>.docs</code> folder.
                </p>
            </header>

            <div class="fi-pp-docs-empty">
                <x-filament::icon
                    icon="heroicon-o-document-text"
                    class="fi-pp-docs-empty__icon"
                />
                <p class="fi-pp-docs-empty__title">No documentation found</p>
                <p class="fi-pp-docs-empty__text">
                    Add Markdown files to
                    <code class="fi-pp-code-pill">.docs/</code>
                    in your project root to display them here.
                </p>
            </div>
        </section>
    @else
        <div class="fi-pp-docs-shell">
            <div class="fi-pp-docs-shell__header">
                <h3>Documentation</h3>
                <p>
                    Browse Markdown files from your project
                    <code>.docs</code> directory, including subfolders.
                </p>
            </div>

            <div class="fi-pp-docs-layout">
                <nav class="fi-pp-docs-nav" aria-label="Documentation files">
                    @include('filament-project-passport::components.docs-nav-tree', [
                        'nodes' => $this->documentTree,
                        'depth' => 0,
                    ])
                </nav>

                <div class="fi-pp-docs-content">
                    @php
                        $active = collect($this->documents)->firstWhere('key', $this->activeDocumentKey);
                    @endphp

                    @if ($active)
                        <p class="fi-pp-docs-path">
                            <span class="fi-pp-mono">{{ $active['relative'] }}</span>
                        </p>
                    @endif

                    <article
                        wire:key="fi-pp-doc-{{ $this->activeDocumentKey }}"
                        class="fi-pp-markdown"
                        x-data="{
                            async renderMermaid() {
                                await window.fiPpEnsureMermaid?.()
                                window.fiPpRenderMermaid?.(this.$el)
                            }
                        }"
                        x-init="$nextTick(() => renderMermaid())"
                        x-on:click="
                            const link = $event.target.closest('a.fi-pp-doc-link')
                            if (! link) return
                            $event.preventDefault()
                            const key = link.getAttribute('data-doc-key')
                            if (key) $wire.selectDocument(key)
                        "
                    >
                        {!! $this->activeDocumentHtml !!}
                    </article>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
    (() => {
        if (window.fiPpEnsureMermaid) {
            return;
        }

        // Pinned to an exact release (not a floating "@11" tag) so the
        // Subresource Integrity hash always matches the fetched file — a
        // floating tag would silently break SRI (and thus the diagram
        // viewer) the moment a new mermaid patch version is published.
        // Both values live in config('filament-project-passport.docs.mermaid').
        const MERMAID_VERSION = {!! Illuminate\Support\Js::from(config('filament-project-passport.docs.mermaid.version')) !!};
        const MERMAID_CDN = `https://cdn.jsdelivr.net/npm/mermaid@${MERMAID_VERSION}/dist/mermaid.min.js`;
        const MERMAID_INTEGRITY = {!! Illuminate\Support\Js::from(config('filament-project-passport.docs.mermaid.integrity')) !!};

        window.fiPpEnsureMermaid = function () {
            if (window.mermaid) {
                return Promise.resolve(window.mermaid);
            }

            if (window.__fiPpMermaidLoading) {
                return window.__fiPpMermaidLoading;
            }

            window.__fiPpMermaidLoading = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = MERMAID_CDN;
                script.integrity = MERMAID_INTEGRITY;
                script.crossOrigin = 'anonymous';
                script.referrerPolicy = 'no-referrer';
                script.async = true;
                script.onload = () => {
                    const dark = document.documentElement.classList.contains('dark');

                    window.mermaid.initialize({
                        startOnLoad: false,
                        theme: dark ? 'dark' : 'default',
                        securityLevel: 'strict',
                        flowchart: { htmlLabels: false },
                    });

                    resolve(window.mermaid);
                };
                script.onerror = () => reject(new Error('Failed to load Mermaid'));
                document.head.appendChild(script);
            });

            return window.__fiPpMermaidLoading;
        };

        window.fiPpRenderMermaid = async function (root) {
            if (! root) {
                return;
            }

            const nodes = [...root.querySelectorAll('.fi-pp-mermaid.mermaid')];

            if (nodes.length === 0) {
                return;
            }

            try {
                const mermaid = await window.fiPpEnsureMermaid();
                await mermaid.run({ nodes });
            } catch (error) {
                console.warn('[filament-project-passport] Mermaid render failed', error);
            }
        };
    })();
</script>
