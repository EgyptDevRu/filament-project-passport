<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Detects whether a usable Node.js / npm CLI is available for Dependency Audit.
 * License inventory does not require this — only outdated/audit CLI scans do.
 *
 * PHP-FPM / queue workers often have a stripped PATH that does not include nvm/fnm
 * shims visible in an interactive SSH shell. Discovery therefore checks absolute
 * binaries, common install roots, and a login-shell `command -v npm` fallback.
 */
final class NpmRuntime
{
    private static ?bool $forcedAvailable = null;

    private static ?bool $resolvedAvailable = null;

    /**
     * @var list<string>|null
     */
    private static ?array $resolvedBinary = null;

    /**
     * Test helper: force availability, or pass null to clear the override.
     */
    public static function fake(?bool $available): void
    {
        self::$forcedAvailable = $available;
        self::$resolvedAvailable = null;
        self::$resolvedBinary = null;
    }

    public static function isAvailable(): bool
    {
        if (self::$forcedAvailable !== null) {
            return self::$forcedAvailable;
        }

        if (self::$resolvedAvailable !== null) {
            return self::$resolvedAvailable;
        }

        self::$resolvedAvailable = self::probe() !== null;

        return self::$resolvedAvailable;
    }

    /**
     * Resolved npm command prefix (absolute path preferred), or null when unavailable.
     *
     * @return list<string>|null
     */
    public static function resolvedBinary(): ?array
    {
        if (self::$forcedAvailable === false) {
            return null;
        }

        if (self::$forcedAvailable === true) {
            return ['npm'];
        }

        if (self::$resolvedBinary !== null) {
            return self::$resolvedBinary;
        }

        self::$resolvedBinary = self::probe();
        self::$resolvedAvailable = self::$resolvedBinary !== null;

        return self::$resolvedBinary;
    }

    public static function unavailableMessage(): string
    {
        return 'Node.js / npm is not available (or is misconfigured) on this server. '
            .'NPM outdated packages and security advisories are not shown because Dependency Audit '
            .'requires the npm CLI. This is expected for installations that ship a pre-compiled '
            .'front-end and never run server-side Node builds. '
            .'If `npm -v` works over SSH but not here, PHP-FPM/queue PATH is missing Node '
            .'(common with nvm/fnm). Install Node system-wide, expose it on the FPM PATH, '
            .'or set FILAMENT_PROJECT_PASSPORT_NPM_BINARY to the absolute npm path, then reload PHP-FPM/queue.';
    }

    /**
     * @return list<list<string>>
     */
    public static function npmBinaryCandidates(): array
    {
        $candidates = [];
        $seen = [];

        $configured = self::configuredBinary();

        if ($configured !== null) {
            self::pushCandidate($candidates, $seen, $configured);
        }

        foreach (self::discoverAbsoluteNpmBinaries() as $path) {
            self::pushCandidate($candidates, $seen, [$path]);
        }

        $loginShell = self::discoverLoginShellNpm();

        if ($loginShell !== null) {
            self::pushCandidate($candidates, $seen, [$loginShell]);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            self::pushCandidate($candidates, $seen, ['npm.cmd']);
            self::pushCandidate($candidates, $seen, ['npm.exe']);
        }

        self::pushCandidate($candidates, $seen, ['npm']);

        return $candidates;
    }

    /**
     * @return list<string>|null
     */
    private static function probe(): ?array
    {
        $env = self::processEnvironment();

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
                return $binary;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function configuredBinary(): ?array
    {
        $configured = config('filament-project-passport.dependency_audit.npm_binary');

        if (! is_string($configured) || trim($configured) === '') {
            $configured = getenv('FILAMENT_PROJECT_PASSPORT_NPM_BINARY') ?: '';
        }

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($configured)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        return $parts !== [] ? $parts : null;
    }

    /**
     * @return list<string>
     */
    private static function discoverAbsoluteNpmBinaries(): array
    {
        $found = [];

        foreach (self::npmSearchDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (['npm', 'npm.cmd', 'npm.exe'] as $name) {
                $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

                if (is_file($path)) {
                    $found[] = $path;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private static function npmSearchDirectories(): array
    {
        $dirs = [];

        if (DIRECTORY_SEPARATOR !== '\\') {
            // Official Node Linux binaries unpacked under /usr/local/lib/nodejs (common on VPS).
            // Prefer newest version first: …/node-v24.6.0/bin before older v*.
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst('/usr/local/lib/nodejs/node-v*/bin'));
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst('/usr/local/nodejs/node-v*/bin'));
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst('/opt/nodejs/node-v*/bin'));
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst('/opt/node/node-v*/bin'));

            $dirs[] = '/usr/local/lib/nodejs/bin';
            $dirs[] = '/usr/local/bin';
            $dirs[] = '/usr/bin';
            $dirs[] = '/opt/nodejs/bin';
            $dirs[] = '/opt/node/bin';
            $dirs[] = '/snap/bin';

            $home = self::homeDirectory();

            if ($home !== null) {
                $dirs[] = $home.'/.local/bin';
                $dirs[] = $home.'/.nvm/current/bin';
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($home.'/.nvm/versions/node/*/bin'));
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($home.'/.fnm/node-versions/*/installation/bin'));
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($home.'/.local/share/fnm/node-versions/*/installation/bin'));
                $dirs = array_merge($dirs, self::globDirectories($home.'/.volta/bin'));
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($home.'/.asdf/installs/nodejs/*/bin'));
                $dirs = array_merge($dirs, self::globDirectories($home.'/.config/herd/bin'));
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($home.'/.nodenv/versions/*/bin'));
            }
        } else {
            $programFiles = (string) (getenv('ProgramFiles') ?: 'C:\\Program Files');
            $programFilesX86 = (string) (getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)');
            $localAppData = (string) (getenv('LOCALAPPDATA') ?: '');
            $userProfile = (string) (getenv('USERPROFILE') ?: '');
            $homeDrive = rtrim((string) (getenv('HOMEDRIVE') ?: 'C:'), '\\/');

            $dirs[] = $programFiles.'\\nodejs';
            $dirs[] = $programFilesX86.'\\nodejs';
            $dirs[] = $programFiles.'\\FlyEnv-Data\\env\\node';
            $dirs[] = $programFiles.'\\FlyEnv-Data\\app\\nodejs';
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($programFiles.'\\FlyEnv-Data\\app\\nodejs-*'));
            $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($programFiles.'\\nodejs\\node-v*'));
            $dirs[] = $homeDrive.'\\nodejs';
            $dirs[] = $homeDrive.'\\nvm';

            if ($localAppData !== '') {
                $dirs[] = $localAppData.'\\Programs\\nodejs';
                $dirs = array_merge($dirs, self::globDirectories($localAppData.'\\fnm_multishells\\*'));
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($localAppData.'\\fnm\\node-versions\\*\\installation'));
            }

            if ($userProfile !== '') {
                $dirs[] = $userProfile.'\\scoop\\apps\\nodejs\\current';
                $dirs[] = $userProfile.'\\scoop\\apps\\nodejs\\current\\bin';
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($userProfile.'\\scoop\\apps\\nodejs\\*'));
                $dirs[] = $userProfile.'\\.config\\herd\\bin';
                $dirs = array_merge($dirs, self::globDirectoriesNewestFirst($userProfile.'\\AppData\\Roaming\\nvm\\v*'));
            }
        }

        return array_values(array_unique(array_filter($dirs, 'is_dir')));
    }

    /**
     * @return list<string>
     */
    private static function globDirectories(string $pattern): array
    {
        $matches = glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT);

        return is_array($matches) ? $matches : [];
    }

    /**
     * Versioned Node dirs (node-v24.6.0, v24.6.0, …) — newest first.
     *
     * @return list<string>
     */
    private static function globDirectoriesNewestFirst(string $pattern): array
    {
        $matches = self::globDirectories($pattern);

        usort($matches, static function (string $a, string $b): int {
            return strnatcasecmp($b, $a);
        });

        return $matches;
    }

    private static function discoverLoginShellNpm(): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        try {
            // Interactive SSH loads nvm/fnm via .bashrc; PHP-FPM does not.
            $process = new Process(
                ['bash', '-lc', 'command -v npm'],
                base_path(),
                self::processEnvironment(),
                null,
                8.0
            );
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        return ($path !== '' && is_file($path)) ? $path : null;
    }

    private static function homeDirectory(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '' && is_dir($value)) {
                return $value;
            }
        }

        // PHP-FPM often omits HOME; fall back to the process owner home.
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());

            if (is_array($info) && $info['dir'] !== '' && is_dir($info['dir'])) {
                return $info['dir'];
            }
        }

        return null;
    }

    /**
     * Environment for npm subprocesses (PATH enriched with common Node install dirs).
     *
     * @return array<string, string>
     */
    public static function inheritedEnvironment(): array
    {
        return self::processEnvironment();
    }

    /**
     * @return array<string, string>
     */
    private static function processEnvironment(): array
    {
        $env = ComposerBinary::inheritedEnvironment();
        $pathKey = isset($env['Path']) && ! isset($env['PATH']) ? 'Path' : 'PATH';
        $path = $env[$pathKey] ?? (string) (getenv($pathKey) ?: getenv('PATH') ?: '');
        $extra = implode(PATH_SEPARATOR, self::npmSearchDirectories());

        if ($extra !== '') {
            $env[$pathKey] = $extra.($path !== '' ? PATH_SEPARATOR.$path : '');
        }

        $home = self::homeDirectory();

        if ($home !== null && ($env['HOME'] ?? '') === '') {
            $env['HOME'] = $home;
        }

        return $env;
    }

    /**
     * @param  list<list<string>>  $candidates
     * @param  array<string, true>  $seen
     * @param  list<string>  $binary
     */
    private static function pushCandidate(array &$candidates, array &$seen, array $binary): void
    {
        $key = implode("\0", $binary);

        if ($binary === [] || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $candidates[] = $binary;
    }
}
