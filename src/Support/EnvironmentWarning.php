<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * One-time after-login warning for non-production Filament sessions.
 */
final class EnvironmentWarning
{
    public const string SESSION_KEY = 'filament_project_passport.env_warning_pending';

    /**
     * True for environments that should warn (local, staging, development, …).
     * Skips production and testing.
     */
    public static function shouldWarn(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        if (app()->environment('testing')) {
            return false;
        }

        return true;
    }

    public static function environmentLabel(): string
    {
        return self::sanitizeLabel((string) app()->environment());
    }

    public static function title(): string
    {
        return 'Non-production environment';
    }

    public static function message(): string
    {
        return 'This site may be unstable, may contain bugs or unfinished work, '
            .'and data can be reset or discarded without notice. '
            .'Do not treat anything here as permanent or production-ready.';
    }

    /**
     * Current request host for display (uppercase). Rejects characters outside a safe hostname set
     * so a spoofed Host header cannot inject markup or control characters into the UI.
     */
    public static function installationDomain(): string
    {
        try {
            $host = (string) request()->getHost();
        } catch (SuspiciousOperationException) {
            return '';
        }

        $safe = preg_replace('/[^a-zA-Z0-9.-]/', '', $host) ?? '';

        return strtoupper($safe);
    }

    public static function markPendingAfterLogin(): void
    {
        if (! self::shouldWarn()) {
            return;
        }

        session([self::SESSION_KEY => true]);
    }

    public static function isPending(): bool
    {
        if (! self::shouldWarn()) {
            return false;
        }

        return (bool) session(self::SESSION_KEY, false);
    }

    public static function dismiss(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private static function sanitizeLabel(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $value) ?? '';

        return $safe !== '' ? $safe : 'unknown';
    }
}
