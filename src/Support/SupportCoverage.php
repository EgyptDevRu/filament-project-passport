<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Interprets warranty dates for a verified (is_official) installation.
 */
final class SupportCoverage
{
    /**
     * @param  array<string, mixed>  $license
     */
    public static function isDomainVerified(array $license): bool
    {
        return (bool) ($license['is_official'] ?? false);
    }

    /**
     * Active EgyptDev.ru support: domain verified and at least one warranty date is not expired.
     *
     * @param  array<string, mixed>  $license
     */
    public static function isSupportActive(array $license, ?CarbonInterface $now = null): bool
    {
        if (! self::isDomainVerified($license)) {
            return false;
        }

        return self::isDateActive($license['support_warranty_until'] ?? null, $now)
            || self::isDateActive($license['extended_support_warranty_until'] ?? null, $now);
    }

    /**
     * Domain is verified, but both warranties are missing or expired.
     *
     * @param  array<string, mixed>  $license
     */
    public static function isVerifiedWithoutActiveSupport(array $license, ?CarbonInterface $now = null): bool
    {
        return self::isDomainVerified($license) && ! self::isSupportActive($license, $now);
    }

    public static function isDateActive(mixed $date, ?CarbonInterface $now = null): bool
    {
        if ($date === null) {
            return false;
        }

        $value = trim((string) $date);

        if ($value === '') {
            return false;
        }

        try {
            $until = Carbon::parse($value)->endOfDay();
        } catch (Throwable) {
            return false;
        }

        $now ??= now();

        return $until->greaterThanOrEqualTo($now->copy()->startOfDay());
    }
}
