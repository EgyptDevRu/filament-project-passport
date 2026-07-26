<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use Composer\InstalledVersions;
use EgyptDevRu\FilamentProjectPassport\Support\ComposerBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Dependency health via local Composer CLI (outdated + audit).
 * Results are cached for 7 days. Network is used only by Composer itself.
 */
final class ComposerDependencyAuditor
{
    public const int CACHE_FRESH_DAYS = 7;

    private const int CACHE_TTL_SECONDS = self::CACHE_FRESH_DAYS * 24 * 60 * 60;

    private const string LARAVEL_PACKAGE = 'laravel/framework';

    private const string FILAMENT_PACKAGE = 'filament/filament';

    /**
     * @return array{
     *     outdated_count: int,
     *     advisory_count: int,
     *     outdated: list<array{name: string, version: string, latest: string, status: string}>,
     *     advisories: list<array{package: string, title: string, cve: ?string, link: ?string, affected_versions: ?string}>,
     *     laravel: array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool},
     *     filament: array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool},
     *     checked_at: string,
     *     from_cache: bool,
     *     error: ?string
     * }
     */
    public function audit(bool $fresh = false): array
    {
        $cacheKey = $this->cacheKey();

        if (! $fresh) {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['checked_at'], $cached['outdated'], $cached['advisories']) && empty($cached['error'])) {
                return array_merge($this->emptyPayload(), $cached, [
                    'from_cache' => true,
                ]);
            }
        }

        $payload = $this->scan();

        // Never cache failed scans — otherwise a one-off CLI/PATH glitch sticks as "0 outdated".
        if (empty($payload['error'])) {
            Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        } else {
            Cache::forget($cacheKey);
        }

        return array_merge($payload, ['from_cache' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->forgetCache();

        return $this->audit(fresh: true);
    }

    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * True when there is no cache, or the last check is at least $days old.
     */
    public function shouldRefresh(int $days = self::CACHE_FRESH_DAYS): bool
    {
        /** @var array{checked_at?: mixed}|null $cached */
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '') {
            return true;
        }

        if (! empty($cached['error'])) {
            return true;
        }

        try {
            $checked = Carbon::parse($cached['checked_at'])->startOfDay();
        } catch (Throwable) {
            return true;
        }

        return $checked->diffInDays(now()->startOfDay()) >= $days;
    }

    public function cacheKey(): string
    {
        $lock = base_path('composer.lock');
        $fingerprint = is_file($lock) ? (string) md5_file($lock) : md5(base_path());

        // v2: invalidate older empty/failed parses cached before CLI/JSON fixes.
        return 'filament-project-passport.dependency-audit.v2.'.$fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(): array
    {
        $payload = $this->emptyPayload();
        $payload['checked_at'] = now()->toIso8601String();

        try {
            $outdated = $this->runComposerJson(['outdated', '--format=json', '--no-interaction', '--no-ansi']);
            $audit = $this->runComposerJson(['audit', '--format=json', '--no-interaction', '--no-ansi']);
        } catch (Throwable $exception) {
            $payload['error'] = $exception->getMessage();

            return $payload;
        }

        $outdatedRows = $this->parseOutdated($outdated);
        $advisoryRows = $this->parseAdvisories($audit);

        $payload['outdated'] = $outdatedRows;
        $payload['advisories'] = $advisoryRows;
        $payload['outdated_count'] = count($outdatedRows);
        $payload['advisory_count'] = count($advisoryRows);
        $payload['laravel'] = $this->frameworkStatus(self::LARAVEL_PACKAGE, $outdatedRows);
        $payload['filament'] = $this->frameworkStatus(self::FILAMENT_PACKAGE, $outdatedRows);

        return $payload;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function runComposerJson(array $arguments): array
    {
        $process = ComposerBinary::run($arguments, base_path(), 180);

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        // composer audit exits non-zero when issues exist; JSON is still on stdout.
        $json = $this->decodeJsonObject($stdout);

        if ($json === null && $stderr !== '') {
            $json = $this->decodeJsonObject($stderr);
        }

        if ($json === null) {
            $snippet = mb_substr($stdout !== '' ? $stdout : $stderr, 0, 400);
            $command = implode(' ', $arguments);

            throw new \RuntimeException(
                "Composer did not return valid JSON for `composer {$command}` (exit {$process->getExitCode()})."
                .($snippet !== '' ? ' Output: '.$snippet : ' Output was empty.')
            );
        }

        return $json;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeJsonObject(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $best = null;

        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length && ($start = strpos($raw, '{', $offset)) !== false) {
            $extracted = $this->extractJsonObject($raw, $start);

            if ($extracted === null) {
                break;
            }

            $decoded = json_decode($extracted, true);

            if (is_array($decoded)) {
                if (array_key_exists('installed', $decoded) || array_key_exists('advisories', $decoded)) {
                    return $decoded;
                }

                $best ??= $decoded;
            }

            $offset = $start + 1;
        }

        return $best;
    }

    private function extractJsonObject(string $raw, int $start): ?string
    {
        $length = strlen($raw);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $ch = $raw[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, version: string, latest: string, status: string}>
     */
    public function parseOutdated(array $payload): array
    {
        $installed = $payload['installed'] ?? [];

        if (! is_array($installed)) {
            return [];
        }

        $rows = [];

        foreach ($installed as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = isset($package['name']) ? (string) $package['name'] : '';

            if ($name === '' || ! str_contains($name, '/')) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'version' => (string) ($package['version'] ?? '—'),
                'latest' => (string) ($package['latest'] ?? '—'),
                'status' => (string) ($package['latest-status'] ?? $package['latest_status'] ?? 'update-possible'),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{package: string, title: string, cve: ?string, link: ?string, affected_versions: ?string}>
     */
    public function parseAdvisories(array $payload): array
    {
        $advisories = $payload['advisories'] ?? [];

        if (! is_array($advisories)) {
            return [];
        }

        $rows = [];

        foreach ($advisories as $packageName => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $rows[] = [
                    'package' => is_string($packageName) ? $packageName : (string) ($item['packageName'] ?? 'unknown'),
                    'title' => (string) ($item['title'] ?? 'Security advisory'),
                    'cve' => isset($item['cve']) ? (string) $item['cve'] : null,
                    'link' => $this->safeHttpUrl(
                        isset($item['link']) ? (string) $item['link'] : (isset($item['advisoryUrl']) ? (string) $item['advisoryUrl'] : null)
                    ),
                    'affected_versions' => isset($item['affectedVersions']) ? (string) $item['affectedVersions'] : null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['package'].$a['title'], $b['package'].$b['title']));

        return $rows;
    }

    private function safeHttpUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  list<array{name: string, version: string, latest: string, status: string}>  $outdated
     * @return array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool}
     */
    private function frameworkStatus(string $package, array $outdated): array
    {
        $installed = $this->installedPrettyVersion($package);
        $installedFlag = $installed !== null;

        foreach ($outdated as $row) {
            if ($row['name'] === $package) {
                return [
                    'installed' => $row['version'] !== '—' ? $row['version'] : $installed,
                    'latest' => $row['latest'] !== '—' ? $row['latest'] : $installed,
                    'up_to_date' => false,
                    'installed_flag' => true,
                ];
            }
        }

        return [
            'installed' => $installed,
            'latest' => $installed,
            'up_to_date' => $installedFlag,
            'installed_flag' => $installedFlag,
        ];
    }

    private function installedPrettyVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            if (! InstalledVersions::isInstalled($package)) {
                return null;
            }

            $version = InstalledVersions::getPrettyVersion($package);

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     outdated_count: int,
     *     advisory_count: int,
     *     outdated: list<array<string, mixed>>,
     *     advisories: list<array<string, mixed>>,
     *     laravel: array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool},
     *     filament: array{installed: ?string, latest: ?string, up_to_date: bool, installed_flag: bool},
     *     checked_at: string,
     *     from_cache: bool,
     *     error: ?string
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'outdated_count' => 0,
            'advisory_count' => 0,
            'outdated' => [],
            'advisories' => [],
            'laravel' => [
                'installed' => null,
                'latest' => null,
                'up_to_date' => false,
                'installed_flag' => false,
            ],
            'filament' => [
                'installed' => null,
                'latest' => null,
                'up_to_date' => false,
                'installed_flag' => false,
            ],
            'checked_at' => now()->toIso8601String(),
            'from_cache' => false,
            'error' => null,
        ];
    }
}
