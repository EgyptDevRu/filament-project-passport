<x-filament-panels::page>
    <div class="fi-pp" wire:init="loadPageData">
        @if (! $this->documentationContentAllowed())
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
