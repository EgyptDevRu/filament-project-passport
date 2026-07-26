<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages\Concerns;

/**
 * Defer heavy page work until after the first HTML response (Livewire wire:init).
 * Avoids Filament page timeouts on cold cache / first install.
 */
trait LoadsPassportDataLazily
{
    public bool $ready = false;

    public function loadPageData(): void
    {
        if ($this->ready) {
            return;
        }

        $this->hydratePassportData();
        $this->ready = true;
    }

    abstract protected function hydratePassportData(): void;
}
