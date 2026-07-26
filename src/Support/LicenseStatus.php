<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * Commercial-distribution license compatibility for Composer packages.
 */
final class LicenseStatus
{
    public const string COMPATIBLE = 'compatible';

    public const string REQUIRES_REVIEW = 'requires_review';

    public const string INCOMPATIBLE = 'incompatible';

    /**
     * @var list<string>
     */
    public const array COMPATIBLE_LICENSES = [
        'MIT',
        'BSD-2-CLAUSE',
        'BSD-3-CLAUSE',
        'APACHE-2.0',
        'ISC',
        'UNLICENSE',
    ];

    /**
     * @var list<string>
     */
    public const array REVIEW_LICENSES = [
        'MPL-2.0',
        'LGPL-2.1',
        'LGPL-3.0',
        'PROPRIETARY',
        'UNKNOWN',
        'CUSTOM',
    ];

    /**
     * @var list<string>
     */
    public const array INCOMPATIBLE_LICENSES = [
        'GPL-2.0',
        'GPL-3.0',
        'AGPL-3.0',
        'SSPL',
        'SSPL-1.0',
    ];

    public static function label(string $status): string
    {
        return match ($status) {
            self::COMPATIBLE => 'Compatible',
            self::INCOMPATIBLE => 'Incompatible',
            default => 'Requires Review',
        };
    }

    /**
     * Normalize a raw SPDX / Composer license string for comparison.
     */
    public static function normalize(string $license): string
    {
        $license = trim($license);

        if ($license === '') {
            return 'UNKNOWN';
        }

        $license = str_replace(['_', ' '], '-', $license);
        $license = strtoupper($license);
        $license = preg_replace('/-(ONLY|OR-LATER)$/', '', $license) ?? $license;

        return match ($license) {
            'BSD2CLAUSE', 'BSD-2' => 'BSD-2-CLAUSE',
            'BSD3CLAUSE', 'BSD-3', 'BSD' => 'BSD-3-CLAUSE',
            'APACHE2', 'APACHE-2', 'APACHE2.0' => 'APACHE-2.0',
            'GPL2', 'GPL-2', 'GPL2.0' => 'GPL-2.0',
            'GPL3', 'GPL-3', 'GPL3.0' => 'GPL-3.0',
            'AGPL3', 'AGPL-3', 'AGPL3.0' => 'AGPL-3.0',
            'LGPL2.1', 'LGPL-2' => 'LGPL-2.1',
            'LGPL3', 'LGPL-3' => 'LGPL-3.0',
            'MPL2', 'MPL-2', 'MPL2.0' => 'MPL-2.0',
            'SSPL1.0' => 'SSPL-1.0',
            default => $license,
        };
    }

    /**
     * Classify a package from its license list.
     *
     * Compatible if at least one compatible license exists.
     * Incompatible only when every license is incompatible.
     *
     * @param  list<string>  $licenses
     */
    public static function classify(array $licenses): string
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn (string $license): string => self::normalize($license), $licenses),
            fn (string $license): bool => $license !== ''
        )));

        if ($normalized === []) {
            return self::REQUIRES_REVIEW;
        }

        foreach ($normalized as $license) {
            if (in_array($license, self::COMPATIBLE_LICENSES, true)) {
                return self::COMPATIBLE;
            }
        }

        $allIncompatible = true;

        foreach ($normalized as $license) {
            if (! in_array($license, self::INCOMPATIBLE_LICENSES, true)) {
                $allIncompatible = false;
                break;
            }
        }

        if ($allIncompatible) {
            return self::INCOMPATIBLE;
        }

        return self::REQUIRES_REVIEW;
    }
}
