<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use Composer\InstalledVersions;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Audits installed Composer package licenses locally (no network).
 *
 * Uses vendor/composer/installed.json (same source Composer CLI uses for
 * license metadata). InstalledVersions / installed.php does not store licenses.
 */
final class ComposerLicenseAuditor
{
    private const int CACHE_TTL_SECONDS = 14 * 24 * 60 * 60;

    /**
     * Fetch (and cache) the license audit for 14 days.
     *
     * @return array{
     *     packages: list<array{
     *         name: string,
     *         version: string,
     *         licenses: list<string>,
     *         license_label: string,
     *         status: string,
     *         status_label: string
     *     }>,
     *     checked_at: string,
     *     from_cache: bool
     * }
     */
    public function audit(bool $fresh = false): array
    {
        $cacheKey = $this->cacheKey();

        if (! $fresh) {
            /** @var array{packages?: mixed, checked_at?: mixed}|null $cached */
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['packages'], $cached['checked_at']) && is_array($cached['packages'])) {
                return [
                    'packages' => array_values($cached['packages']),
                    'checked_at' => (string) $cached['checked_at'],
                    'from_cache' => true,
                ];
            }
        }

        $packages = $this->scanPackages();
        $checkedAt = now()->toIso8601String();

        Cache::put($cacheKey, [
            'packages' => $packages,
            'checked_at' => $checkedAt,
        ], self::CACHE_TTL_SECONDS);

        return [
            'packages' => $packages,
            'checked_at' => $checkedAt,
            'from_cache' => false,
        ];
    }

    /**
     * Bust the cache and re-scan installed packages.
     *
     * @return array{
     *     packages: list<array<string, mixed>>,
     *     checked_at: string,
     *     from_cache: bool
     * }
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
    public function shouldRefresh(int $days = 14): bool
    {
        /** @var array{checked_at?: mixed}|null $cached */
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '') {
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
        $fingerprint = File::isFile($lock)
            ? (string) md5_file($lock)
            : md5(base_path());

        return 'filament-project-passport.license-audit.'.$fingerprint;
    }

    /**
     * @return list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     status: string,
     *     status_label: string
     * }>
     */
    public function packages(): array
    {
        return $this->audit()['packages'];
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    public function incompatiblePackages(array $packages): array
    {
        return array_values(array_filter(
            $packages,
            fn (array $package): bool => ($package['status'] ?? null) === LicenseStatus::INCOMPATIBLE
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    public function hasIncompatible(array $packages): bool
    {
        return $this->incompatiblePackages($packages) !== [];
    }

    /**
     * @return list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     status: string,
     *     status_label: string
     * }>
     */
    private function scanPackages(): array
    {
        $rows = [];
        $rootName = $this->rootPackageName();

        foreach ($this->installedPackageRecords() as $record) {
            $name = $record['name'];

            if ($rootName !== null && $name === $rootName) {
                continue;
            }

            if (! $this->isAuditablePackageName($name)) {
                continue;
            }

            $licenses = $record['licenses'];
            $status = LicenseStatus::classify($licenses);

            $rows[] = [
                'name' => $name,
                'version' => $record['version'],
                'licenses' => $licenses,
                'license_label' => $licenses === [] ? 'Unknown' : implode(' + ', $licenses),
                'status' => $status,
                'status_label' => LicenseStatus::label($status),
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $rows;
    }

    /**
     * @return list<array{name: string, version: string, licenses: list<string>}>
     */
    private function installedPackageRecords(): array
    {
        $fromJson = $this->recordsFromInstalledJson();

        if ($fromJson !== []) {
            return $fromJson;
        }

        return $this->recordsFromInstalledVersionsFallback();
    }

    /**
     * @return list<array{name: string, version: string, licenses: list<string>}>
     */
    private function recordsFromInstalledJson(): array
    {
        $path = base_path('vendor/composer/installed.json');

        if (! File::isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed>|list<mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        /** @var list<array<string, mixed>> $packages */
        $packages = [];

        if (isset($json['packages']) && is_array($json['packages'])) {
            $packages = array_values(array_filter(
                $json['packages'],
                fn (mixed $package): bool => is_array($package)
            ));
        } elseif (array_is_list($json)) {
            $packages = array_values(array_filter(
                $json,
                fn (mixed $package): bool => is_array($package)
            ));
        }

        $records = [];

        foreach ($packages as $package) {
            $name = isset($package['name']) ? (string) $package['name'] : '';

            if ($name === '') {
                continue;
            }

            $licenses = $this->normalizeLicenseList($package['license'] ?? null);

            if ($licenses === []) {
                $licenses = $this->licensesFromPackageComposerJson($package);
            }

            $version = isset($package['version']) && is_string($package['version']) && $package['version'] !== ''
                ? $package['version']
                : '—';

            $records[] = [
                'name' => $name,
                'version' => $version,
                'licenses' => $licenses,
            ];
        }

        return $records;
    }

    /**
     * Fallback when installed.json is missing: versions from InstalledVersions,
     * licenses from each package's composer.json.
     *
     * @return list<array{name: string, version: string, licenses: list<string>}>
     */
    private function recordsFromInstalledVersionsFallback(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $records = [];

        try {
            foreach (InstalledVersions::getInstalledPackages() as $name) {
                if ($name === '' || $name === '__root__') {
                    continue;
                }

                $version = '—';

                try {
                    $pretty = InstalledVersions::getPrettyVersion($name);
                    if (is_string($pretty) && $pretty !== '') {
                        $version = $pretty;
                    }
                } catch (Throwable) {
                    // keep placeholder
                }

                $installPath = null;

                try {
                    $installPath = InstalledVersions::getInstallPath($name);
                } catch (Throwable) {
                    $installPath = null;
                }

                $licenses = [];

                if (is_string($installPath) && $installPath !== '') {
                    $licenses = $this->licensesFromComposerJsonFile($installPath.DIRECTORY_SEPARATOR.'composer.json');
                }

                $records[] = [
                    'name' => $name,
                    'version' => $version,
                    'licenses' => $licenses,
                ];
            }
        } catch (Throwable) {
            return $records;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private function licensesFromPackageComposerJson(array $package): array
    {
        $installPath = $package['install-path'] ?? null;

        if (! is_string($installPath) || $installPath === '') {
            return [];
        }

        $composerDir = base_path('vendor/composer');
        $resolved = realpath($composerDir.DIRECTORY_SEPARATOR.$installPath);

        if ($resolved === false) {
            return [];
        }

        return $this->licensesFromComposerJsonFile($resolved.DIRECTORY_SEPARATOR.'composer.json');
    }

    /**
     * @return list<string>
     */
    private function licensesFromComposerJsonFile(string $path): array
    {
        if (! File::isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return $this->normalizeLicenseList($json['license'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function normalizeLicenseList(mixed $license): array
    {
        if (is_string($license)) {
            $license = trim($license);

            return $license === '' ? [] : [$license];
        }

        if (! is_array($license)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $item): string {
                return trim((string) $item);
            },
            $license
        )));
    }

    private function rootPackageName(): ?string
    {
        $path = base_path('composer.json');

        if (! File::isFile($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $name = $json['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function isAuditablePackageName(string $name): bool
    {
        if ($name === 'php' || $name === 'hhvm' || $name === 'composer-plugin-api' || $name === '__root__') {
            return false;
        }

        if (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
            return false;
        }

        return str_contains($name, '/');
    }
}
