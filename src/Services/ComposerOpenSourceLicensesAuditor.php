<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Collects installed Composer packages with full LICENSE file text (local only).
 *
 * Cache lifetime matches License Audit (14 days).
 */
final class ComposerOpenSourceLicensesAuditor
{
    private const int CACHE_TTL_SECONDS = 14 * 24 * 60 * 60;

    private const int MAX_LICENSE_BYTES = 512 * 1024;

    /**
     * @var list<string>
     */
    private const array LICENSE_FILENAMES = [
        'LICENSE',
        'LICENSE.txt',
        'LICENSE.md',
        'LICENSE.MD',
        'License',
        'license',
        'license.txt',
        'license.md',
        'LICENCE',
        'LICENCE.txt',
        'LICENCE.md',
        'COPYING',
        'COPYING.txt',
        'UNLICENSE',
        'UNLICENSE.txt',
    ];

    /**
     * @return array{
     *     packages: list<array{
     *         name: string,
     *         version: string,
     *         licenses: list<string>,
     *         license_label: string,
     *         license_text: string,
     *         license_file: string|null,
     *         has_license_text: bool
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

        return 'filament-project-passport.open-source-licenses.'.$fingerprint;
    }

    /**
     * @return list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     license_label: string,
     *     license_text: string,
     *     license_file: string|null,
     *     has_license_text: bool
     * }>
     */
    private function scanPackages(): array
    {
        $rows = [];
        $rootName = $this->rootPackageName();
        $vendorRoot = $this->vendorRoot();

        foreach ($this->installedPackageRecords() as $record) {
            $name = $record['name'];

            if ($rootName !== null && $name === $rootName) {
                continue;
            }

            if (! $this->isAuditablePackageName($name)) {
                continue;
            }

            $licenses = $record['licenses'];
            $licenseFile = $this->findLicenseFile($record['install_path'], $vendorRoot);
            $licenseText = $licenseFile !== null
                ? $this->readLicenseText($licenseFile, $vendorRoot)
                : '';

            $rows[] = [
                'name' => $name,
                'version' => $record['version'],
                'licenses' => $licenses,
                'license_label' => $licenses === [] ? 'Unknown' : implode(' + ', $licenses),
                'license_text' => $licenseText,
                'license_file' => $licenseFile !== null ? basename($licenseFile) : null,
                'has_license_text' => $licenseText !== '',
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $rows;
    }

    /**
     * @return list<array{name: string, version: string, licenses: list<string>, install_path: string|null}>
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
     * @return list<array{name: string, version: string, licenses: list<string>, install_path: string|null}>
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

        $composerDir = base_path('vendor/composer');
        $records = [];

        foreach ($packages as $package) {
            $name = isset($package['name']) ? (string) $package['name'] : '';

            if ($name === '') {
                continue;
            }

            $licenses = $this->normalizeLicenseList($package['license'] ?? null);
            $installPath = $this->resolveInstallPathFromRecord($package, $composerDir);

            if ($licenses === [] && $installPath !== null) {
                $licenses = $this->licensesFromComposerJsonFile($installPath.DIRECTORY_SEPARATOR.'composer.json');
            }

            $version = isset($package['version']) && is_string($package['version']) && $package['version'] !== ''
                ? $package['version']
                : '—';

            $records[] = [
                'name' => $name,
                'version' => $version,
                'licenses' => $licenses,
                'install_path' => $installPath,
            ];
        }

        return $records;
    }

    /**
     * @return list<array{name: string, version: string, licenses: list<string>, install_path: string|null}>
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
                    $path = InstalledVersions::getInstallPath($name);
                    if (is_string($path) && $path !== '') {
                        $resolved = realpath($path);
                        $installPath = $resolved !== false ? $resolved : null;
                    }
                } catch (Throwable) {
                    $installPath = null;
                }

                $licenses = [];

                if ($installPath !== null) {
                    $licenses = $this->licensesFromComposerJsonFile($installPath.DIRECTORY_SEPARATOR.'composer.json');
                }

                $records[] = [
                    'name' => $name,
                    'version' => $version,
                    'licenses' => $licenses,
                    'install_path' => $installPath,
                ];
            }
        } catch (Throwable) {
            return $records;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function resolveInstallPathFromRecord(array $package, string $composerDir): ?string
    {
        $installPath = $package['install-path'] ?? null;

        if (! is_string($installPath) || $installPath === '') {
            return null;
        }

        $resolved = realpath($composerDir.DIRECTORY_SEPARATOR.$installPath);

        return $resolved !== false ? $resolved : null;
    }

    private function findLicenseFile(?string $installPath, string $vendorRoot): ?string
    {
        if ($installPath === null || $installPath === '') {
            return null;
        }

        if (! $this->pathIsInside($installPath, $vendorRoot)) {
            return null;
        }

        foreach (self::LICENSE_FILENAMES as $filename) {
            $candidate = $installPath.DIRECTORY_SEPARATOR.$filename;

            if (! is_file($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved === false || ! $this->pathIsInside($resolved, $vendorRoot)) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    private function readLicenseText(string $path, string $vendorRoot): string
    {
        if (! $this->pathIsInside($path, $vendorRoot) || ! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $size = filesize($path);

        if ($size === false || $size <= 0) {
            return '';
        }

        try {
            $contents = (string) File::get($path);
        } catch (Throwable) {
            return '';
        }

        $contents = str_replace("\0", '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $contents = is_string($converted) ? $converted : '';
        }

        if (strlen($contents) > self::MAX_LICENSE_BYTES) {
            $contents = substr($contents, 0, self::MAX_LICENSE_BYTES)."\n\n[Truncated: license file exceeds size limit.]";
        }

        return trim($contents);
    }

    private function pathIsInside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        return str_starts_with($path, $root) || $path === rtrim($root, '/');
    }

    private function vendorRoot(): string
    {
        $resolved = realpath(base_path('vendor'));

        return $resolved !== false ? $resolved : base_path('vendor');
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
