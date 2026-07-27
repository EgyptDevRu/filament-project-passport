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
                            // Defined inline (not in a <script> tag) on purpose: Filament
                            // panels navigate between pages via Livewire's wire:navigate,
                            // which swaps the DOM without a real page load, so a plain
                            // <script> tag here would only ever run once per browser
                            // session (or never, if this page wasn't the first one
                            // visited). Alpine re-runs x-init for every element it mounts
                            // regardless of how it entered the DOM, so putting the loader
                            // here is what makes it reliably fire on every doc visit.
                            ensureMermaid() {
                                if (window.mermaid) {
                                    return Promise.resolve(window.mermaid);
                                }

                                if (window.__fiPpMermaidLoading) {
                                    return window.__fiPpMermaidLoading;
                                }

                                const version = {!! Illuminate\Support\Js::from(config('filament-project-passport.docs.mermaid.version')) !!};
                                const integrity = {!! Illuminate\Support\Js::from(config('filament-project-passport.docs.mermaid.integrity')) !!};
                                const cdn = `https://cdn.jsdelivr.net/npm/mermaid@${version}/dist/mermaid.min.js`;

                                window.__fiPpMermaidLoading = new Promise((resolve, reject) => {
                                    const load = (withIntegrity) => {
                                        const script = document.createElement('script');
                                        script.src = cdn;

                                        if (withIntegrity) {
                                            script.integrity = integrity;
                                            script.crossOrigin = 'anonymous';
                                            script.referrerPolicy = 'no-referrer';
                                        }

                                        script.async = true;
                                        script.onload = () => {
                                            if (! window.mermaid) {
                                                script.remove();

                                                if (withIntegrity) {
                                                    load(false);

                                                    return;
                                                }

                                                reject(new Error('Failed to load Mermaid'));

                                                return;
                                            }

                                            const dark = document.documentElement.classList.contains('dark');

                                            window.mermaid.initialize({
                                                startOnLoad: false,
                                                theme: dark ? 'dark' : 'default',
                                                securityLevel: 'strict',
                                                flowchart: { htmlLabels: false },
                                            });

                                            resolve(window.mermaid);
                                        };
                                        script.onerror = () => {
                                            script.remove();

                                            // The SRI-checked request can fail for reasons unrelated
                                            // to the file itself (CDN edge hiccup, a proxy stripping
                                            // CORS headers, etc). Retry once without integrity/CORS
                                            // so a diagram viewer never breaks outright over a
                                            // transient network condition.
                                            if (withIntegrity) {
                                                load(false);

                                                return;
                                            }

                                            reject(new Error('Failed to load Mermaid'));
                                        };
                                        document.head.appendChild(script);
                                    };

                                    load(true);
                                });

                                window.__fiPpMermaidLoading.catch(() => {
                                    window.__fiPpMermaidLoading = null;
                                });

                                return window.__fiPpMermaidLoading;
                            },
                            async renderMermaid() {
                                const nodes = [...this.$el.querySelectorAll('.fi-pp-mermaid.mermaid')];

                                if (nodes.length === 0) {
                                    return;
                                }

                                let mermaid;

                                try {
                                    mermaid = await this.ensureMermaid();
                                } catch (error) {
                                    console.warn('[filament-project-passport] Mermaid failed to load', error);

                                    return;
                                }

                                // Render each diagram independently so one malformed
                                // diagram does not leave every other diagram on the
                                // page as raw text.
                                for (const node of nodes) {
                                    try {
                                        await mermaid.run({ nodes: [node] });
                                    } catch (error) {
                                        console.warn('[filament-project-passport] Mermaid render failed', error);
                                    }
                                }
                            },
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
