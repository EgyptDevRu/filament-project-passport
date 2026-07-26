<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use Composer\InstalledVersions;
use EgyptDevRu\FilamentProjectPassport\Jobs\RefreshDependencyAuditJob;
use EgyptDevRu\FilamentProjectPassport\Support\ComposerBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dependency health via local Composer CLI (outdated + audit).
 * Results are cached for 7 days. Network is used only by Composer itself.
 */
final class ComposerDependencyAuditor
{
    public const int CACHE_FRESH_DAYS = 7;

    private const int CACHE_TTL_SECONDS = self::CACHE_FRESH_DAYS * 24 * 60 * 60;

    /** Failed scans are cached briefly so the UI can show the error via poll. */
    private const int FAILURE_CACHE_TTL_SECONDS = 10 * 60;

    /**
     * Overall wall-clock cap for a single scan() call (both Composer sub-calls
     * combined). Keep RefreshDependencyAuditJob::$timeout comfortably above
     * this value so the queue worker never kills the job before this limit
     * would naturally stop it.
     */
    public const int MAX_SCAN_SECONDS = 600;

    /** Per Composer sub-call timeout — two calls run sequentially per scan(). */
    private const float COMPOSER_CALL_TIMEOUT_SECONDS = 240.0;

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

        try {
            if (! $fresh) {
                $cached = $this->cachedPayload();

                if ($cached !== null) {
                    return $cached;
                }
            }

            $payload = $this->scan();

            $ttl = empty($payload['error'])
                ? self::CACHE_TTL_SECONDS
                : self::FAILURE_CACHE_TTL_SECONDS;

            Cache::put($cacheKey, $payload, $ttl);

            return array_merge($payload, ['from_cache' => false]);
        } finally {
            $this->clearRefreshRunning();
        }
    }

    /**
     * Successful cached payload only — never runs Composer. Null when missing/invalid/error.
     *
     * @return array<string, mixed>|null
     */
    public function cachedPayload(): ?array
    {
        $completed = $this->completedPayload();

        if ($completed === null || ! empty($completed['error'])) {
            return null;
        }

        return $completed;
    }

    /**
     * Last finished scan (success or error) for UI polling. Null while still running / never run.
     *
     * @return array<string, mixed>|null
     */
    public function completedPayload(): ?array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['checked_at'], $cached['outdated'], $cached['advisories'])) {
            return null;
        }

        return array_merge($this->emptyPayload(), $cached, [
            'from_cache' => true,
        ]);
    }

    /**
     * Ask for a refresh without blocking the HTTP/Livewire request (Filament-style).
     *
     * Order:
     * 1) Real queue worker (non-sync) → RefreshDependencyAuditJob
     * 2) Detached `artisan` process (returns immediately; Composer runs elsewhere)
     *
     * Never uses afterResponse() — on many hosts that keeps the browser waiting
     * until Composer finishes.
     *
     * Returns false when a refresh is already in progress.
     */
    public function dispatchBackgroundRefresh(bool $force = true): bool
    {
        if (! $this->markRefreshRunning()) {
            return false;
        }

        // Avoid running Composer during Pest/PHPUnit.
        if (app()->runningUnitTests()) {
            return true;
        }

        if ($this->shouldUseQueue()) {
            RefreshDependencyAuditJob::dispatch($force);

            return true;
        }

        $this->spawnDetachedArtisanRefresh($force);

        return true;
    }

    public function shouldUseQueue(): bool
    {
        if (! (bool) config('filament-project-passport.dependency_audit.use_queue', true)) {
            return false;
        }

        $connection = (string) config('queue.default', 'sync');

        return $connection !== '' && $connection !== 'sync';
    }

    public function isRefreshRunning(): bool
    {
        return Cache::has($this->runningLockKey());
    }

    public function markRefreshRunning(): bool
    {
        // Stay above RefreshDependencyAuditJob::$timeout so the lock cannot
        // expire (and be re-dispatched) while that job is still finishing.
        return Cache::add($this->runningLockKey(), true, self::MAX_SCAN_SECONDS + 120);
    }

    public function clearRefreshRunning(): void
    {
        Cache::forget($this->runningLockKey());
    }

    /**
     * Persist a failed scan so the UI can leave the loading state via poll.
     *
     * @return array<string, mixed>
     */
    public function rememberFailure(string $message): array
    {
        $payload = $this->emptyPayload();
        $payload['error'] = $message;

        Cache::put($this->cacheKey(), $payload, self::FAILURE_CACHE_TTL_SECONDS);
        $this->clearRefreshRunning();

        return array_merge($payload, ['from_cache' => false]);
    }

    /**
     * Empty UI payload for first paint when cache is cold (no Composer, no fake checked_at).
     *
     * @return array<string, mixed>
     */
    public function placeholderPayload(): array
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
            'checked_at' => null,
            'from_cache' => false,
            'error' => null,
        ];
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

    private function runningLockKey(): string
    {
        return $this->cacheKey().'.running';
    }

    /**
     * Fire-and-forget artisan refresh so the Livewire request can return immediately.
     *
     * Uses Symfony Process with array arguments (no shell string, no injection
     * surface) plus the `create_new_console` option, which tells Process to
     * close its pipes instead of stopping/waiting for the child in its
     * destructor — letting the artisan command keep running after this
     * request ends, on both Windows and *nix.
     */
    private function spawnDetachedArtisanRefresh(bool $force): void
    {
        $arguments = [
            ComposerBinary::phpCliBinary(),
            base_path('artisan'),
            'filament-project-passport:refresh-dependency-audit',
        ];

        if ($force) {
            $arguments[] = '--force';
        }

        $process = new Process($arguments, base_path(), ComposerBinary::inheritedEnvironment());
        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();

        try {
            $process->start();
        } catch (Throwable) {
            // Best effort — a stuck queue/host surfaces via the client-side timeout instead.
        }
    }

    /**
     * True when there is no cache, or the last check is at least $days old.
     */
    public function shouldRefresh(int $days = self::CACHE_FRESH_DAYS): bool
    {
        /** @var array{checked_at?: mixed, error?: mixed}|null $cached */
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '') {
            return true;
        }

        if (array_key_exists('error', $cached) && filled($cached['error'])) {
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

        // v3: SSH→HTTPS git rewrite + error-cache / poll fixes.
        return 'filament-project-passport.dependency-audit.v3.'.$fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(): array
    {
        // Composer outdated/audit can take a while on a cold install, but an
        // unbounded (0) limit removes PHP's own safety net entirely. Bound it
        // to the same cap the queue job's timeout is coordinated against.
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_SCAN_SECONDS);
        }

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
        $process = ComposerBinary::run($arguments, base_path(), self::COMPOSER_CALL_TIMEOUT_SECONDS);

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
