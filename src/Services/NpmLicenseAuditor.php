<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use EgyptDevRu\FilamentProjectPassport\Support\NpmPackageInventory;
use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Audits installed NPM package licenses locally (no network).
 */
final class NpmLicenseAuditor
{
    private const int CACHE_TTL_SECONDS = 14 * 24 * 60 * 60;

    /**
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

        // No frontend NPM project (Blade/Alpine-only hosts): keep a one-time empty
        // cache for checked_at, then stop scheduling further npm license work.
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
        return 'filament-project-passport.npm-license-audit.'.NpmPackageInventory::fingerprint();
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
        try {
            $rows = [];

            foreach (NpmPackageInventory::records() as $record) {
                $licenses = $record['licenses'];
                $status = LicenseStatus::classify($licenses);

                $rows[] = [
                    'name' => $record['name'],
                    'version' => $record['version'],
                    'licenses' => $licenses,
                    'license_label' => $licenses === [] ? 'Unknown' : implode(' + ', $licenses),
                    'status' => $status,
                    'status_label' => LicenseStatus::label($status),
                ];
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }
}
