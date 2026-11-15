<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use EgyptDevRu\FilamentProjectPassport\Support\NpmPackageInventory;
use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Collects installed NPM packages with full LICENSE file text (local only).
 */
final class NpmOpenSourceLicensesAuditor
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
            $cached = SignedCachePayload::open(Cache::get($cacheKey));

            if ($cached !== null && isset($cached['packages'], $cached['checked_at']) && is_array($cached['packages'])) {
                return [
                    'packages' => array_values($cached['packages']),
                    'checked_at' => (string) $cached['checked_at'],
                    'from_cache' => true,
                ];
            }
        }

        $packages = $this->scanPackages();
        $checkedAt = now()->toIso8601String();

        Cache::put($cacheKey, SignedCachePayload::seal([
            'packages' => $packages,
            'checked_at' => $checkedAt,
        ]), self::CACHE_TTL_SECONDS);

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
        $cached = SignedCachePayload::open(Cache::get($this->cacheKey()));

        if (! NpmPackageInventory::hasManifest()) {
            return $cached === null || ! isset($cached['checked_at']) || ! is_string($cached['checked_at']) || $cached['checked_at'] === '';
        }

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

    public function cacheKey(): string
    {
        return 'filament-project-passport.npm-open-source-licenses.'.NpmPackageInventory::fingerprint();
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
        try {
            $rows = [];

            foreach (NpmPackageInventory::records() as $record) {
                $licenses = $record['licenses'];
                $licenseFile = $this->findLicenseFile($record['install_path']);
                $licenseText = $licenseFile !== null
                    ? $this->readLicenseText($licenseFile, $record['install_path'])
                    : '';

                $rows[] = [
                    'name' => $record['name'],
                    'version' => $record['version'],
                    'licenses' => $licenses,
                    'license_label' => $licenses === [] ? 'Unknown' : implode(' + ', $licenses),
                    'license_text' => $licenseText,
                    'license_file' => $licenseFile !== null ? basename($licenseFile) : null,
                    'has_license_text' => $licenseText !== '',
                ];
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private function findLicenseFile(?string $installPath): ?string
    {
        if ($installPath === null || $installPath === '') {
            return null;
        }

        $packageRoot = realpath($installPath);

        if ($packageRoot === false || ! is_dir($packageRoot)) {
            return null;
        }

        foreach (self::LICENSE_FILENAMES as $filename) {
            $candidate = $packageRoot.DIRECTORY_SEPARATOR.$filename;

            if (! is_file($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved === false || ! $this->pathIsInside($resolved, $packageRoot)) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    private function readLicenseText(string $path, ?string $installPath): string
    {
        if ($installPath === null || $installPath === '') {
            return '';
        }

        $packageRoot = realpath($installPath);

        if ($packageRoot === false || ! $this->pathIsInside($path, $packageRoot) || ! is_file($path) || ! is_readable($path)) {
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
}
