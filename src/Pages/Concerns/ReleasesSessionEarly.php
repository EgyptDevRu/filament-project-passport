<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages\Concerns;

/**
 * Release the session lock early so a reload / second tab is not blocked
 * while this Livewire request (or a sibling poll) is still open.
 *
 * PHP file sessions are exclusive: one request holds the lock until the
 * script ends unless we close the session after reading it.
 */
trait ReleasesSessionEarly
{
    protected function releaseSessionLockEarly(): void
    {
        try {
            if (app()->bound('session') && session()->isStarted()) {
                session()->save();
            }

            // Native lock (file sessions): save() alone is not always enough.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        } catch (\Throwable) {
            // Host session drivers vary; never fail the page for this.
        }
    }
}
