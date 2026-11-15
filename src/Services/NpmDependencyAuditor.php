<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use EgyptDevRu\FilamentProjectPassport\Support\ComposerBinary;
use EgyptDevRu\FilamentProjectPassport\Support\NpmPackageInventory;
use EgyptDevRu\FilamentProjectPassport\Support\NpmRuntime;
use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Frontend dependency health via local npm CLI (outdated + audit).
 * Cache lifetime matches Composer dependency audit (7 days).
 * Skipped entirely when Node.js / npm is not available on the host.
 */
final class NpmDependencyAuditor
{
    public const int CACHE_FRESH_DAYS = 7;

    private const int CACHE_TTL_SECONDS = self::CACHE_FRESH_DAYS * 24 * 60 * 60;

    private const int FAILURE_CACHE_TTL_SECONDS = 10 * 60;

    private const float NPM_CALL_TIMEOUT_SECONDS = 180.0;

    /**
     * Wall-clock budget for outdated + audit (two sequential npm calls).
     * RefreshDependencyAuditJob timeout must cover Composer + this value.
     */
    public const int MAX_SCAN_SECONDS = 360;

    /**
     * @return array{
     *     outdated_count: int,
     *     advisory_count: int,
     *     high_advisory_count: int,
     *     outdated: list<array{name: string, version: string, latest: string, status: string}>,
     *     advisories: list<array{package: string, title: string, severity: string, cve: ?string, link: ?string, affected_versions: ?string}>,
     *     checked_at: string|null,
     *     from_cache: bool,
     *     error: ?string,
     *     available: bool,
     *     runnable: bool,
     *     node_available: bool,
     *     skipped: bool,
     *     message: ?string
     * }
     */
    public function audit(bool $fresh = false): array
    {
        if (! NpmRuntime::isAvailable()) {
            return $this->nodeUnavailablePayload();
        }

        if (! $this->hasPackageManifest()) {
            return $this->unavailablePayload('No package.json / package-lock.json found in the application root.');
        }

        $cacheKey = $this->cacheKey();

        if (! $fresh) {
            $cached = $this->completedPayload();

            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $payload = $this->scan();
        } catch (Throwable $exception) {
            $payload = $this->emptyPayload();
            $payload['error'] = $exception->getMessage();
            $payload['checked_at'] = now()->toIso8601String();
            $payload['runnable'] = true;
            $payload['node_available'] = true;
            Cache::put($cacheKey, SignedCachePayload::seal($payload), self::FAILURE_CACHE_TTL_SECONDS);

            return array_merge($payload, ['from_cache' => false]);
        }

        $ttl = empty($payload['error'])
            ? self::CACHE_TTL_SECONDS
            : self::FAILURE_CACHE_TTL_SECONDS;

        Cache::put($cacheKey, SignedCachePayload::seal($payload), $ttl);

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

    /**
     * True when a package manifest exists and npm CLI can run on this host.
     */
    public function isRunnable(): bool
    {
        return NpmRuntime::isAvailable() && $this->hasPackageManifest();
    }

    /**
     * True when Dependency Audit still needs a background npm outdated/audit scan.
     */
    public function needsBackgroundScan(): bool
    {
        return $this->isRunnable() && $this->completedPayload() === null;
    }

    public function nodeIsAvailable(): bool
    {
        return NpmRuntime::isAvailable();
    }

    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Persist a failed npm scan so Dependency Audit can leave the loading state.
     *
     * @return array<string, mixed>
     */
    public function rememberFailure(string $message): array
    {
        $payload = $this->emptyPayload();
        $payload['error'] = $message;
        $payload['checked_at'] = now()->toIso8601String();
        $payload['available'] = true;
        $payload['runnable'] = true;
        $payload['node_available'] = true;
        $payload['skipped'] = false;

        Cache::put($this->cacheKey(), SignedCachePayload::seal($payload), self::FAILURE_CACHE_TTL_SECONDS);

        return array_merge($payload, ['from_cache' => false]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function completedPayload(): ?array
    {
        if (! NpmRuntime::isAvailable()) {
            return null;
        }

        $cached = SignedCachePayload::open(Cache::get($this->cacheKey()));

        if ($cached === null || ! array_key_exists('outdated', $cached) || ! array_key_exists('advisories', $cached)) {
            return null;
        }

        return array_merge($this->emptyPayload(), $cached, [
            'from_cache' => true,
            'available' => true,
            'runnable' => true,
            'node_available' => true,
            'skipped' => false,
        ]);
    }

    public function shouldRefresh(int $days = self::CACHE_FRESH_DAYS): bool
    {
        if (! $this->isRunnable()) {
            return false;
        }

        $cached = SignedCachePayload::open(Cache::get($this->cacheKey()));

        if ($cached === null || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '') {
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
        return 'filament-project-passport.npm-dependency-audit.v1.'.NpmPackageInventory::fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    public function placeholderPayload(): array
    {
        if (! NpmRuntime::isAvailable()) {
            return $this->nodeUnavailablePayload();
        }

        $payload = $this->emptyPayload();
        $payload['checked_at'] = null;
        $payload['available'] = $this->hasPackageManifest();
        $payload['runnable'] = $this->isRunnable();
        $payload['node_available'] = true;
        $payload['skipped'] = false;

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $advisories
     */
    public function highAdvisoryCount(array $advisories): int
    {
        return count(array_filter(
            $advisories,
            fn (array $row): bool => $this->isHighSeverity((string) ($row['severity'] ?? ''))
        ));
    }

    public function isHighSeverity(string $severity): bool
    {
        return in_array(strtolower(trim($severity)), ['critical', 'high'], true);
    }

    public function hasPackageManifest(): bool
    {
        return NpmPackageInventory::hasManifest();
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(): array
    {
        if (app()->runningUnitTests()) {
            $payload = $this->emptyPayload();
            $payload['checked_at'] = now()->toIso8601String();
            $payload['available'] = true;
            $payload['runnable'] = true;
            $payload['node_available'] = true;
            $payload['skipped'] = false;

            return $payload;
        }

        $payload = $this->emptyPayload();
        $payload['checked_at'] = now()->toIso8601String();
        $payload['available'] = true;
        $payload['runnable'] = true;
        $payload['node_available'] = true;
        $payload['skipped'] = false;

        $outdatedJson = $this->runNpmJson(['outdated', '--json', '--long']);
        $auditJson = $this->runNpmJson(['audit', '--json']);

        $outdatedRows = $this->parseOutdated($outdatedJson);
        $advisoryRows = $this->parseAdvisories($auditJson);

        $payload['outdated'] = $outdatedRows;
        $payload['advisories'] = $advisoryRows;
        $payload['outdated_count'] = count($outdatedRows);
        $payload['advisory_count'] = count($advisoryRows);
        $payload['high_advisory_count'] = $this->highAdvisoryCount($advisoryRows);

        return $payload;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function runNpmJson(array $arguments): array
    {
        $failures = [];
        $env = ComposerBinary::inheritedEnvironment();

        foreach (NpmRuntime::npmBinaryCandidates() as $binary) {
            $command = array_merge($binary, $arguments);
            $label = implode(' ', $binary);

            try {
                $process = new Process($command, base_path(), $env, null, self::NPM_CALL_TIMEOUT_SECONDS);
                $process->run();
            } catch (Throwable $exception) {
                $failures[] = $label.': '.$exception->getMessage();

                continue;
            }

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $raw = $stdout !== '' ? $stdout : $stderr;

            if ($raw === '') {
                // npm outdated with no outdated packages often prints nothing (exit 0).
                if (($arguments[0] ?? null) === 'outdated') {
                    return [];
                }

                $failures[] = $label.': empty output (exit '.$process->getExitCode().')';

                continue;
            }

            // Prefer a JSON object from stdout; npm may print warnings on stderr.
            if ($stdout !== '') {
                $raw = $stdout;
            }

            try {
                /** @var array<string, mixed> $json */
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $failures[] = $label.': invalid JSON ('.$exception->getMessage().')';

                continue;
            }

            return $json;
        }

        $message = 'npm CLI is not available or did not return usable JSON for `npm '.implode(' ', $arguments).'`.';

        if ($failures !== []) {
            $message .= ' Details: '.implode('; ', $failures);
        }

        throw new \RuntimeException($message);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, version: string, latest: string, status: string}>
     */
    public function parseOutdated(array $payload): array
    {
        $rows = [];

        foreach ($payload as $name => $meta) {
            if (! is_string($name) || $name === '' || ! is_array($meta)) {
                continue;
            }

            // Skip npm metadata keys if present.
            if (in_array($name, ['error', 'code', 'summary'], true)) {
                continue;
            }

            $current = (string) ($meta['current'] ?? '—');
            $latest = (string) ($meta['latest'] ?? $meta['wanted'] ?? '—');
            $wanted = (string) ($meta['wanted'] ?? '');

            $status = 'update-possible';

            if ($wanted !== '' && $latest !== '—' && version_compare(ltrim($wanted, 'v'), ltrim($latest, 'v'), '>=')) {
                $status = 'semver-safe-update';
            }

            $rows[] = [
                'name' => $name,
                'version' => $current !== '' ? $current : '—',
                'latest' => $latest !== '' ? $latest : '—',
                'status' => $status,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{package: string, title: string, severity: string, cve: ?string, link: ?string, affected_versions: ?string}>
     */
    public function parseAdvisories(array $payload): array
    {
        $vulnerabilities = $payload['vulnerabilities'] ?? [];

        if (! is_array($vulnerabilities)) {
            return [];
        }

        $rows = [];

        foreach ($vulnerabilities as $name => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $package = is_string($name) && $name !== ''
                ? $name
                : (string) ($meta['name'] ?? 'unknown');

            $severity = strtolower((string) ($meta['severity'] ?? 'unknown'));
            $range = isset($meta['range']) ? (string) $meta['range'] : null;
            $via = $meta['via'] ?? [];
            $title = 'Security advisory';
            $cve = null;
            $link = null;

            if (is_array($via)) {
                foreach ($via as $entry) {
                    if (is_string($entry)) {
                        $title = $entry;

                        continue;
                    }

                    if (! is_array($entry)) {
                        continue;
                    }

                    if (isset($entry['title']) && is_string($entry['title']) && $entry['title'] !== '') {
                        $title = $entry['title'];
                    }

                    if (isset($entry['url']) && is_string($entry['url'])) {
                        $link = $this->safeHttpUrl($entry['url']);
                    }

                    if (isset($entry['cve']) && is_string($entry['cve'])) {
                        $cve = $entry['cve'];
                    } elseif (isset($entry['source']) && is_numeric($entry['source'])) {
                        // npm advisory id — keep link if present
                    }

                    break;
                }
            }

            $rows[] = [
                'package' => $package,
                'title' => $title,
                'severity' => $severity !== '' ? $severity : 'unknown',
                'cve' => $cve,
                'link' => $link,
                'affected_versions' => $range,
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b): int => strcasecmp($a['package'].$a['title'], $b['package'].$b['title'])
        );

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
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'outdated_count' => 0,
            'advisory_count' => 0,
            'high_advisory_count' => 0,
            'outdated' => [],
            'advisories' => [],
            'checked_at' => now()->toIso8601String(),
            'from_cache' => false,
            'error' => null,
            'available' => false,
            'runnable' => false,
            'node_available' => NpmRuntime::isAvailable(),
            'skipped' => false,
            'message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailablePayload(string $message): array
    {
        $payload = $this->emptyPayload();
        $payload['checked_at'] = now()->toIso8601String();
        $payload['available'] = false;
        $payload['runnable'] = false;
        $payload['node_available'] = true;
        $payload['skipped'] = false;
        $payload['error'] = null;
        $payload['message'] = $message;
        $payload['from_cache'] = false;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeUnavailablePayload(): array
    {
        $payload = $this->emptyPayload();
        $payload['checked_at'] = now()->toIso8601String();
        $payload['available'] = false;
        $payload['runnable'] = false;
        $payload['node_available'] = false;
        $payload['skipped'] = true;
        $payload['error'] = null;
        $payload['message'] = NpmRuntime::unavailableMessage();
        $payload['from_cache'] = false;

        return $payload;
    }
}
