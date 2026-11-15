<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Reports the runtime PHP version and any other PHP binaries found on the host
 * for the Dependency Audit "PHP2SHELL Status" widget.
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

    /**
     * @return array{
     *     runtime: array{version: string, major: int, minor: int, patch: int, status: string, label: string, minimum_secure: ?string, build_date: ?string, build_after_security_release: bool},
     *     binaries: list<array{path: string, version: string, major: int, minor: int, patch: int, status: string, label: string, minimum_secure: ?string, build_date: ?string, build_after_security_release: bool}>,
     *     is_secure: bool,
     *     has_critical: bool,
     *     has_suspect: bool,
     *     cloudflare_note: string
     * }
     */
    public static function report(): array
    {
        $runtimeVersion = PHP_VERSION;
        $runtimeBuildDate = defined('PHP_BUILD_DATE') && is_string(PHP_BUILD_DATE) ? PHP_BUILD_DATE : null;
        $runtime = self::classifyVersion($runtimeVersion, $runtimeBuildDate);
        $runtime['version'] = $runtimeVersion;

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

        $hasCritical = $runtime['status'] === 'critical';
        $hasSuspect = $runtime['status'] === 'suspect';

        foreach ($binaries as $binary) {
            if ($binary['status'] === 'critical') {
                $hasCritical = true;
            }

            if ($binary['status'] === 'suspect') {
                $hasSuspect = true;
            }
        }

        $isSecure = $runtime['status'] === 'ok';

        if ($isSecure) {
            foreach ($binaries as $binary) {
                if (in_array($binary['status'], ['critical', 'outdated', 'suspect'], true)) {
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
        $buildDateLabel = $parsedBuild?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';

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
        $found = [];

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

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
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

            // Skip cgi/fpm wrappers for the version list where possible.
            if (str_contains($base, 'cgi') || str_contains($base, 'fpm') || str_contains($base, 'phpdbg')) {
                continue;
            }

            $found[] = $line;
        }

        return array_values(array_unique($found));
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
