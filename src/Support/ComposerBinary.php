<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Resolves and runs Composer CLI via global `composer` or project `composer.phar`.
 */
final class ComposerBinary
{
    /**
     * Candidate command prefixes, in preference order.
     *
     * @return list<list<string>>
     */
    public static function candidates(?string $basePath = null): array
    {
        $basePath ??= base_path();
        $candidates = [];

        // 1) Project-local composer.phar via a real CLI php binary
        //    (PHP_BINARY under php-cgi / php-fpm cannot reliably run phars).
        $phar = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.phar';

        if (is_file($phar)) {
            $candidates[] = [self::phpCliBinary(), $phar];
        }

        // 2) Global composer on PATH
        $candidates[] = ['composer'];

        // 3) Windows composer.bat if present beside php (best-effort)
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = ['composer.bat'];
        }

        return $candidates;
    }

    /**
     * Resolve a PHP CLI binary suitable for running composer.phar.
     */
    public static function phpCliBinary(): string
    {
        $binary = PHP_BINARY;
        $normalized = strtolower(str_replace('\\', '/', $binary));

        $isNonCli = str_contains($normalized, 'php-cgi')
            || str_contains($normalized, 'php-fpm')
            || str_contains($normalized, '/cgi')
            || str_ends_with($normalized, 'cgi.exe')
            || str_ends_with($normalized, 'fpm.exe');

        if (! $isNonCli) {
            return $binary;
        }

        $dir = dirname($binary);
        $names = DIRECTORY_SEPARATOR === '\\'
            ? ['php.exe', 'php']
            : ['php'];

        foreach ($names as $name) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$name;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
    }

    /**
     * Run a Composer CLI command, trying each available binary until one works.
     *
     * @param  list<string>  $arguments  e.g. ['audit', '--format=json']
     */
    public static function run(array $arguments, ?string $workingDirectory = null, float $timeoutSeconds = 180): Process
    {
        $cwd = $workingDirectory ?? base_path();
        $failures = [];
        $env = self::processEnvironment();

        foreach (self::candidates($cwd) as $binary) {
            $command = array_merge($binary, $arguments);
            $label = implode(' ', $binary);

            try {
                $process = new Process($command, $cwd, $env, null, $timeoutSeconds);
                $process->run();
            } catch (Throwable $exception) {
                $failures[] = $label.': '.$exception->getMessage();

                continue;
            }

            // composer audit/outdated may exit non-zero when issues exist — treat as usable
            // when it produced output or exited successfully.
            if ($process->isSuccessful() || trim($process->getOutput()) !== '' || trim($process->getErrorOutput()) !== '') {
                return $process;
            }

            $failures[] = $label.': exit '.$process->getExitCode();
        }

        $message = 'Composer CLI is not available. Tried project composer.phar and global composer.';

        if ($failures !== []) {
            $message .= ' Details: '.implode('; ', $failures);
        }

        throw new \RuntimeException($message);
    }

    /**
     * Probe which Composer binary would be used (for diagnostics).
     *
     * @return list<string>|null
     */
    public static function resolve(?string $basePath = null): ?array
    {
        $cwd = $basePath ?? base_path();

        foreach (self::candidates($cwd) as $binary) {
            try {
                $process = new Process(
                    array_merge($binary, ['--version', '--no-ansi']),
                    $cwd,
                    self::processEnvironment(),
                    null,
                    30
                );
                $process->run();
            } catch (Throwable) {
                continue;
            }

            if ($process->isSuccessful() || str_contains(strtolower($process->getOutput().$process->getErrorOutput()), 'composer')) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function processEnvironment(): array
    {
        $env = [];

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }

        foreach (['PATH', 'Path', 'SystemRoot', 'USERPROFILE', 'HOME', 'APPDATA', 'LOCALAPPDATA', 'COMPOSER_HOME'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['COMPOSER_NO_INTERACTION'] = '1';
        $env['COMPOSER_DISABLE_XDEBUG_WARN'] = '1';

        return $env;
    }

    /**
     * Environment passed to Composer / artisan subprocesses (host PATH, etc.).
     *
     * @return array<string, string>
     */
    public static function inheritedEnvironment(): array
    {
        return self::processEnvironment();
    }
}
