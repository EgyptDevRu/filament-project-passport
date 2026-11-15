<x-filament-panels::page>
    <div
        class="fi-pp"
        dir="ltr"
        wire:init="loadPageData"
        @if ($this->refreshing)
            wire:poll.3s="pollPageData"
        @endif
    >
        @include('filament-project-passport::components.security-audit')
    </div>
</x-filament-panels::page>
