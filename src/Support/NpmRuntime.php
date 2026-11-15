<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Detects whether a usable Node.js / npm CLI is available for Dependency Audit.
 * License inventory does not require this — only outdated/audit CLI scans do.
 */
final class NpmRuntime
{
    private static ?bool $forcedAvailable = null;

    private static ?bool $resolvedAvailable = null;

    /**
     * Test helper: force availability, or pass null to clear the override.
     */
    public static function fake(?bool $available): void
    {
        self::$forcedAvailable = $available;
        self::$resolvedAvailable = null;
    }

    public static function isAvailable(): bool
    {
        if (self::$forcedAvailable !== null) {
            return self::$forcedAvailable;
        }

        if (self::$resolvedAvailable !== null) {
            return self::$resolvedAvailable;
        }

        self::$resolvedAvailable = self::probe();

        return self::$resolvedAvailable;
    }

    public static function unavailableMessage(): string
    {
        return 'Node.js / npm is not available (or is misconfigured) on this server. '
            .'NPM outdated packages and security advisories are not shown because Dependency Audit '
            .'requires the npm CLI. This is expected for installations that ship a pre-compiled '
            .'front-end and never run server-side Node builds.';
    }

    /**
     * @return list<list<string>>
     */
    public static function npmBinaryCandidates(): array
    {
        $candidates = [];

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = ['npm.cmd'];
            $candidates[] = ['npm.exe'];
        }

        $candidates[] = ['npm'];

        return $candidates;
    }

    private static function probe(): bool
    {
        $env = ComposerBinary::inheritedEnvironment();

        foreach (self::npmBinaryCandidates() as $binary) {
            try {
                $process = new Process(
                    array_merge($binary, ['--version']),
                    base_path(),
                    $env,
                    null,
                    8.0
                );
                $process->run();
            } catch (Throwable) {
                continue;
            }

            $output = trim($process->getOutput().' '.$process->getErrorOutput());

            // npm --version prints e.g. "10.9.2"
            if ($process->isSuccessful() && preg_match('/\d+\.\d+/', $output) === 1) {
                return true;
            }
        }

        return false;
    }
}
