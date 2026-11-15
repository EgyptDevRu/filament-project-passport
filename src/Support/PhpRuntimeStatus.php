<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reports the runtime PHP version and other PHP binaries found on the host
 * for the Security Audit "PHP2SHELL Status" widget.
 *
 * Discovery:
 * - Linux: `command -v php` plus `/usr/bin/php*` and `/usr/local/bin/php*`
 * - Windows: `where php` plus common multi-version roots (e.g. FlyEnv-Data\app\php-*\php.exe)
 *
 * CGI / FPM / phpdbg wrapper binaries are skipped; CLI `php.exe` / `php` are kept.
 *
 * Minimum secure patch levels track the 21 Oct 2026 critical RCE security release
 * (CVE-2026-109618 / CVE-2026-109619 family): 8.5.12, 8.4.27, 8.3.36, 8.2.35.
 *
 * Builds dated after that release window (~13:30–14:00 UTC) that still report a
 * version below the branch minimum get a yellowish "possible security problem"
 * status (custom/distro rebuild without the security bump).
 */
final class PhpRuntimeStatus
{
    /**
     * Lowest secure patch for each supported minor line (inclusive).
     *
     * @var array<string, string>
     */
    private const array MINIMUM_SECURE = [
        '8.5' => '8.5.12',
        '8.4' => '8.4.27',
        '8.3' => '8.3.36',
        '8.2' => '8.2.35',
    ];

    /**
     * Security releases published ~13:30–14:00 UTC on 21 Oct 2026.
     * Builds after this instant are treated as post-advisory.
     */
    public const string SECURITY_RELEASE_CUTOFF_UTC = '2026-10-21 14:00:00';

    public const int CACHE_FRESH_DAYS = 7;

    private const int CACHE_TTL_SECONDS = self::CACHE_FRESH_DAYS * 24 * 60 * 60;

    private const string CACHE_KEY = 'filament-project-passport.php2shell.v1';

    /**
     * Cached host PHP binary scan (7 days), with live runtime overlay.
     * Cold cache triggers a scan; Security Audit refresh / dependency job force a rescan.
     *
     * @return array{
     *     runtime: array{version: string, major: int, minor: int, patch: int, status: string, label: string, minimum_secure: ?string, build_date: ?string, build_after_security_release: bool},
     *     binaries: list<array{path: string, version: string, major: int, minor: int, patch: int, status: string, label: string, minimum_secure: ?string, build_date: ?string, build_after_security_release: bool}>,
     *     is_secure: bool,
     *     has_critical: bool,
     *     has_suspect: bool,
     *     checked_at: string|null,
     *     from_cache: bool,
     *     cloudflare_note: string
     * }
     */
    public static function report(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = self::cachedPayload();

            if ($cached !== null) {
                return $cached;
            }
        }

        return self::scanAndRemember();
    }

    /**
     * @return array<string, mixed>
     */
    public static function refresh(): array
    {
        self::forgetCache();

        return self::report(fresh: true);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::cacheKey());
    }

    public static function cacheKey(): string
    {
        return self::CACHE_KEY;
    }

    public static function shouldRefresh(int $days = self::CACHE_FRESH_DAYS): bool
    {
        $cached = SignedCachePayload::open(Cache::get(self::cacheKey()));

        if ($cached === null || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '') {
            return true;
        }

        try {
            $checked = Carbon::parse($cached['checked_at'])->startOfDay();
        } catch (Throwable) {
            return true;
        }

        return $checked->diffInDays(now()->startOfDay()) >= $days;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function cachedPayload(): ?array
    {
        $cached = SignedCachePayload::open(Cache::get(self::cacheKey()));

        if ($cached === null || ! array_key_exists('binaries', $cached)) {
            return null;
        }

        /** @var list<array<string, mixed>> $binaries */
        $binaries = is_array($cached['binaries']) ? array_values($cached['binaries']) : [];
        $checkedAt = isset($cached['checked_at']) && is_string($cached['checked_at']) ? $cached['checked_at'] : null;

        return self::assembleReport($binaries, $checkedAt, fromCache: true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function scanAndRemember(): array
    {
        $binaries = self::discoverHostBinaries();
        $checkedAt = now()->toIso8601String();

        Cache::put(self::cacheKey(), SignedCachePayload::seal([
            'binaries' => $binaries,
            'checked_at' => $checkedAt,
        ]), self::CACHE_TTL_SECONDS);

        return self::assembleReport($binaries, $checkedAt, fromCache: false);
    }

    /**
     * @return list<array{path: string, version: string, major: int, minor: int, patch: int, status: string, label: string, minimum_secure: ?string, build_date: ?string, build_after_security_release: bool}>
     */
    private static function discoverHostBinaries(): array
    {
        $binaries = [];
        $seen = [self::normalizePath(PHP_BINARY) => true];

        foreach (self::discoverBinaries() as $path) {
            $normalized = self::normalizePath($path);

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $meta = self::metaFromBinary($path);

            if ($meta === null) {
                continue;
            }

            $row = self::classifyVersion($meta['version'], $meta['build_date']);
            $row['path'] = $path;
            $row['version'] = $meta['version'];
            $binaries[] = $row;
        }

        usort(
            $binaries,
            fn (array $a, array $b): int => [$a['major'], $a['minor'], $a['patch']] <=> [$b['major'], $b['minor'], $b['patch']]
        );

        return $binaries;
    }

    /**
     * Live runtime is always recomputed; host binary list may come from cache.
     *
     * @param  list<array<string, mixed>>  $binaries
     * @return array<string, mixed>
     */
    private static function assembleReport(array $binaries, ?string $checkedAt, bool $fromCache): array
    {
        $runtimeVersion = PHP_VERSION;
        $runtimeBuildDate = defined('PHP_BUILD_DATE') && is_string(PHP_BUILD_DATE) ? PHP_BUILD_DATE : null;
        $runtime = self::classifyVersion($runtimeVersion, $runtimeBuildDate);
        $runtime['version'] = $runtimeVersion;

        $runtimePath = self::normalizePath(PHP_BINARY);
        $binaries = array_values(array_filter(
            $binaries,
            function (array $binary) use ($runtimePath): bool {
                $path = $binary['path'] ?? null;

                return is_string($path) && $path !== '' && self::normalizePath($path) !== $runtimePath;
            }
        ));

        $hasCritical = $runtime['status'] === 'critical';
        $hasSuspect = $runtime['status'] === 'suspect';

        foreach ($binaries as $binary) {
            if (($binary['status'] ?? '') === 'critical') {
                $hasCritical = true;
            }

            if (($binary['status'] ?? '') === 'suspect') {
                $hasSuspect = true;
            }
        }

        $isSecure = $runtime['status'] === 'ok';

        if ($isSecure) {
            foreach ($binaries as $binary) {
                if (in_array($binary['status'] ?? '', ['critical', 'outdated', 'suspect'], true)) {
                    $isSecure = false;
                    break;
                }
            }
        }

        return [
            'runtime' => $runtime,
            'binaries' => $binaries,
            'is_secure' => $isSecure,
            'has_critical' => $hasCritical,
            'has_suspect' => $hasSuspect,
            'checked_at' => $checkedAt,
            'from_cache' => $fromCache,
            'cloudflare_note' => 'If this application is behind Cloudflare, Cloudflare can help mitigate many remote exploitation attempts (including common PHP webshell patterns), but it is not a substitute for keeping PHP updated. An emergency PHP update is still strongly recommended whenever an outdated or unsupported PHP binary is present.',
        ];
    }

    /**
     * @return array{
     *     major: int,
     *     minor: int,
     *     patch: int,
     *     status: string,
     *     label: string,
     *     minimum_secure: ?string,
     *     build_date: ?string,
     *     build_after_security_release: bool
     * }
     */
    public static function classifyVersion(string $version, ?string $buildDate = null): array
    {
        [$major, $minor, $patch] = self::parseVersion($version);
        $branch = $major.'.'.$minor;
        $minimum = self::MINIMUM_SECURE[$branch] ?? null;
        $parsedBuild = self::parseBuildDate($buildDate);
        $buildAfterRelease = $parsedBuild !== null && $parsedBuild >= self::securityReleaseCutoff();
        // Avoid `null.' UTC'` → literal "UTC" when PHP_BUILD_DATE is missing.
        $buildDateLabel = $parsedBuild !== null
            ? $parsedBuild->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC'
            : null;

        $base = [
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
            'minimum_secure' => $minimum,
            'build_date' => $buildDateLabel,
            'build_after_security_release' => $buildAfterRelease,
        ];

        // PHP < 8.2 (including all EOL lines) — remove ASAP.
        if ($major < 8 || ($major === 8 && $minor < 2)) {
            $status = ($major === 8 && $minor === 1) ? 'outdated' : 'critical';
            $label = $status === 'outdated'
                ? 'End of life — update required'
                : 'Unsupported — remove ASAP';

            return array_merge($base, [
                'status' => $status,
                'label' => $label,
                'minimum_secure' => null,
            ]);
        }

        // Unknown future minor on an active major — treat as OK until we ship a floor.
        if ($minimum === null) {
            if ($major > 8 || ($major === 8 && $minor > 5)) {
                return array_merge($base, [
                    'status' => 'ok',
                    'label' => 'Supported',
                ]);
            }

            return array_merge($base, [
                'status' => 'outdated',
                'label' => 'Unknown branch — update required',
            ]);
        }

        if (version_compare(self::normalizeComparable($version), $minimum, '>=')) {
            return array_merge($base, [
                'status' => 'ok',
                'label' => 'Supported (min '.$minimum.')',
            ]);
        }

        // Version below the security floor, but compiled after the advisory window —
        // possible custom/distro rebuild that never bumped the reported version.
        if ($buildAfterRelease) {
            return array_merge($base, [
                'status' => 'suspect',
                'label' => 'Possible security problem — build date is after 21 Oct 2026 14:00 UTC, but version is still below '.$minimum,
            ]);
        }

        return array_merge($base, [
            'status' => 'outdated',
            'label' => 'Security update required — upgrade to '.$minimum.' or newer',
        ]);
    }

    public static function securityReleaseCutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::SECURITY_RELEASE_CUTOFF_UTC, new DateTimeZone('UTC'));
    }

    /**
     * Parse PHP_BUILD_DATE strings such as "Oct 21 2026 15:12:03".
     */
    public static function parseBuildDate(?string $buildDate): ?DateTimeImmutable
    {
        if ($buildDate === null) {
            return null;
        }

        $buildDate = trim($buildDate);

        if ($buildDate === '') {
            return null;
        }

        $formats = [
            'M j Y H:i:s',
            'M d Y H:i:s',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s\Z',
        ];

        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $buildDate, new DateTimeZone('UTC'));

            if ($parsed instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();

                if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                    continue;
                }

                return $parsed->setTimezone(new DateTimeZone('UTC'));
            }
        }

        try {
            return (new DateTimeImmutable($buildDate))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function parseVersion(string $version): array
    {
        $normalized = self::normalizeComparable($version);
        $parts = array_map('intval', explode('.', $normalized));

        return [
            $parts[0] ?? 0,
            $parts[1] ?? 0,
            $parts[2] ?? 0,
        ];
    }

    /**
     * Strip pre-release / build metadata so version_compare works on X.Y.Z.
     */
    public static function normalizeComparable(string $version): string
    {
        $version = trim($version);

        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d+\.\d+)/', $version, $matches) === 1) {
            return $matches[1].'.0';
        }

        return '0.0.0';
    }

    /**
     * @return list<string>
     */
    private static function discoverBinaries(): array
    {
        $candidates = array_merge(
            self::discoverBinariesFromPathCommand(),
            self::discoverBinariesFromKnownRoots(),
        );

        $found = [];

        foreach ($candidates as $line) {
            $line = trim($line);

            if ($line === '' || ! is_file($line)) {
                continue;
            }

            $base = strtolower(basename($line));

            if (DIRECTORY_SEPARATOR === '\\') {
                if (! str_ends_with($base, 'php.exe') && $base !== 'php') {
                    continue;
                }
            } elseif (! str_starts_with($base, 'php')) {
                continue;
            }

            // Skip cgi/fpm/phpdbg wrappers — keep CLI php / php.exe only.
            // (FlyEnv may label a version folder "PHP-FPM"; the CLI binary is still php.exe.)
            if (str_contains($base, 'cgi') || str_contains($base, 'fpm') || str_contains($base, 'phpdbg')) {
                continue;
            }

            $found[] = $line;
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private static function discoverBinariesFromPathCommand(): array
    {
        try {
            $command = DIRECTORY_SEPARATOR === '\\'
                ? ['where', 'php']
                : ['sh', '-c', 'command -v php; ls /usr/bin/php* /usr/local/bin/php* 2>/dev/null'];
            $process = new \Symfony\Component\Process\Process($command, null, ComposerBinary::inheritedEnvironment(), null, 5.0);
            $process->run();
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        } catch (Throwable) {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $output) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
    }

    /**
     * Multi-version roots that are often off PATH (FlyEnv, Laragon, XAMPP, Winget, etc.).
     *
     * @return list<string>
     */
    private static function discoverBinariesFromKnownRoots(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        $found = [];

        foreach (self::windowsPhpScanRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            self::collectPhpExeFromDirectory($root, $found);

            try {
                $entries = scandir($root) ?: [];
            } catch (Throwable) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || ! self::looksLikePhpVersionDirectory($entry)) {
                    continue;
                }

                $dir = $root.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($dir)) {
                    self::collectPhpExeFromDirectory($dir, $found);
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function windowsPhpScanRoots(): array
    {
        $programFiles = (string) (getenv('ProgramFiles') ?: 'C:\\Program Files');
        $programFilesX86 = (string) (getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)');
        $localAppData = (string) (getenv('LOCALAPPDATA') ?: '');
        $userProfile = (string) (getenv('USERPROFILE') ?: '');
        $homeDrive = rtrim((string) (getenv('HOMEDRIVE') ?: 'C:'), '\\/');

        $roots = [
            $homeDrive.'\\php',
            $homeDrive.'\\xampp\\php',
            $homeDrive.'\\laragon\\bin\\php',
            $homeDrive.'\\FlyEnv\\app',
            $homeDrive.'\\FlyEnv\\app\\php',
            $homeDrive.'\\tools',
            $homeDrive.'\\OSPanel\\modules',
            $homeDrive.'\\OpenServer\\modules\\php',
            $homeDrive.'\\wamp64\\bin\\php',
            $homeDrive.'\\wamp\\bin\\php',
            $homeDrive.'\\MAMP\\bin\\php',
            $homeDrive.'\\Winginx',
            $programFiles.'\\PHP',
            $programFiles.'\\FlyEnv-Data\\app',
            $programFiles.'\\FlyEnv-Data\\env\\php',
            $programFiles.'\\FlyEnv\\app',
            $programFilesX86.'\\PHP',
            $programFilesX86.'\\FlyEnv-Data\\app',
        ];

        if ($localAppData !== '') {
            $roots[] = $localAppData.'\\Programs\\PHP';
        }

        if ($userProfile !== '') {
            $roots[] = $userProfile.'\\.flyenv\\app';
            $roots[] = $userProfile.'\\.flyenv\\app\\php';
            $roots[] = $userProfile.'\\.config\\herd\\bin';
            $roots[] = $userProfile.'\\scoop\\apps\\php';
        }

        return array_values(array_unique(array_filter($roots, fn (string $path): bool => $path !== '')));
    }

    /**
     * @param  list<string>  $found
     */
    private static function collectPhpExeFromDirectory(string $directory, array &$found): void
    {
        foreach (['php.exe', 'php'] as $name) {
            $binary = $directory.DIRECTORY_SEPARATOR.$name;

            if (is_file($binary)) {
                $found[] = $binary;
            }
        }
    }

    private static function looksLikePhpVersionDirectory(string $entry): bool
    {
        // php-8.4.22, php8.4, php84, PHP-8.3, PHP_8.2, v8.3, 8.3.0, current (Scoop)
        return preg_match('/^(php[-_]?|v)?\d+(\.\d+)*$/i', $entry) === 1
            || preg_match('/^php[-_]?\d/i', $entry) === 1
            || strcasecmp($entry, 'current') === 0;
    }

    /**
     * @return array{version: string, build_date: ?string}|null
     */
    private static function metaFromBinary(string $path): ?array
    {
        try {
            $process = new \Symfony\Component\Process\Process(
                [$path, '-r', 'echo PHP_VERSION, "\\n", (defined("PHP_BUILD_DATE") ? PHP_BUILD_DATE : "");'],
                null,
                ComposerBinary::inheritedEnvironment(),
                null,
                5.0
            );
            $process->run();
            $out = trim($process->getOutput());
        } catch (Throwable) {
            return null;
        }

        if ($out === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\n|\r/', $out) ?: [];
        $version = trim((string) ($lines[0] ?? ''));
        $buildDate = trim((string) ($lines[1] ?? ''));

        if ($version === '' || ! preg_match('/^\d+\.\d+/', $version)) {
            return null;
        }

        return [
            'version' => $version,
            'build_date' => $buildDate !== '' ? $buildDate : null,
        ];
    }

    private static function normalizePath(string $path): string
    {
        $resolved = realpath($path);

        return strtolower(str_replace('\\', '/', $resolved !== false ? $resolved : $path));
    }
}
