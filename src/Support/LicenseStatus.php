<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * Commercial-distribution license compatibility for Composer / NPM packages.
 *
 * SPDX identifiers are compared after normalize() (uppercase, -only/-or-later stripped).
 * Dual-license arrays and "A OR B" expressions are compatible if any option is compatible.
 * "A AND B" expressions require every license to be compatible (worst status wins).
 */
final class LicenseStatus
{
    public const string COMPATIBLE = 'compatible';

    public const string REQUIRES_REVIEW = 'requires_review';

    public const string INCOMPATIBLE = 'incompatible';

    /**
     * Normalized (uppercase) SPDX ids treated as commercial-friendly.
     *
     * @var list<string>
     */
    public const array COMPATIBLE_LICENSES = [
        'MIT',
        'BSD-2-CLAUSE',
        'BSD-3-CLAUSE',
        'BSD-3-CLAUSE-CLEAR',
        'BSD-4-CLAUSE',
        'APACHE-2.0',
        'ISC',
        '0BSD',
        'ZLIB',
        'CC0-1.0',
        'CC-BY-3.0', // Attribute in THIRD-PARTY-LICENSES.txt
        'CC-BY-4.0', // Attribute in THIRD-PARTY-LICENSES.txt
        'MPL-2.0', // Attribute in THIRD-PARTY-LICENSES.txt
        'PYTHON-2.0',
        'PSF-2.0',
        'POSTGRESQL',
        'BLUEOAK-1.0.0',
        'UNLICENSE',
        'WTFPL',
    ];

    /**
     * Normalized SPDX ids that need manual legal review (not auto-compatible).
     *
     * @var list<string>
     */
    public const array REVIEW_LICENSES = [
        'LGPL-2.0',
        'LGPL-2.1',
        'LGPL-3.0',
        'EPL-1.0',
        'EPL-2.0',
        'EUPL-1.1',
        'EUPL-1.2',
        'CDDL-1.0',
        'CDDL-1.1',
        'PROPRIETARY',
        'UNKNOWN',
        'CUSTOM',
    ];

    /**
     * Normalized SPDX ids incompatible with proprietary commercial distribution
     * when they are the only option (or ANDed with others).
     *
     * @var list<string>
     */
    public const array INCOMPATIBLE_LICENSES = [
        'GPL-1.0',
        'GPL-2.0',
        'GPL-3.0',
        'AGPL-1.0',
        'AGPL-3.0',
        'SSPL',
        'SSPL-1.0',
        'OSL-3.0',
        'CPAL-1.0',
        'BUSL-1.1',
        'APACHE-2.0-WITH-COMMONS-CLAUSE',
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
     * Normalize a raw SPDX / Composer / NPM license string for comparison.
     *
     * Strips SPDX -only / -or-later suffixes so GPL-3.0-only ≡ GPL-3.0.
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
            'BSD3CLAUSECLEAR', 'BSD-3-CLAUSECLEAR' => 'BSD-3-CLAUSE-CLEAR',
            'APACHE2', 'APACHE-2', 'APACHE2.0' => 'APACHE-2.0',
            'GPL1', 'GPL-1', 'GPL1.0' => 'GPL-1.0',
            'GPL2', 'GPL-2', 'GPL2.0' => 'GPL-2.0',
            'GPL3', 'GPL-3', 'GPL3.0' => 'GPL-3.0',
            'AGPL1', 'AGPL-1', 'AGPL1.0' => 'AGPL-1.0',
            'AGPL3', 'AGPL-3', 'AGPL3.0' => 'AGPL-3.0',
            'LGPL2', 'LGPL-2', 'LGPL2.0' => 'LGPL-2.0',
            'LGPL2.1', 'LGPL-2.1' => 'LGPL-2.1',
            'LGPL3', 'LGPL-3', 'LGPL3.0' => 'LGPL-3.0',
            'MPL2', 'MPL-2', 'MPL2.0' => 'MPL-2.0',
            'SSPL1.0' => 'SSPL-1.0',
            'CC0', 'CC-0', 'CC01.0' => 'CC0-1.0',
            'CCBY-3.0', 'CC-BY3.0' => 'CC-BY-3.0',
            'CCBY-4.0', 'CC-BY4.0' => 'CC-BY-4.0',
            default => $license,
        };
    }

    /**
     * Expand a license field that may be a SPDX expression or a list into atomic ids.
     *
     * Composer/NPM string arrays are treated as OR (dual-license choice).
     * A single "A OR B" string is OR; "A AND B" is AND.
     *
     * @param  list<string>|string  $licenses
     * @return array{mode: 'or'|'and', licenses: list<string>}
     */
    public static function parseExpression(array|string $licenses): array
    {
        if (is_string($licenses)) {
            $licenses = [$licenses];
        }

        $licenses = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $licenses
        ), static fn (string $item): bool => $item !== ''));

        if ($licenses === []) {
            return ['mode' => 'or', 'licenses' => []];
        }

        if (count($licenses) === 1) {
            $expression = self::stripOuterParens($licenses[0]);

            if (self::containsConjunction($expression, 'AND') && ! self::containsConjunction($expression, 'OR')) {
                return [
                    'mode' => 'and',
                    'licenses' => self::splitConjunction($expression, 'AND'),
                ];
            }

            if (self::containsConjunction($expression, 'OR') && ! self::containsConjunction($expression, 'AND')) {
                return [
                    'mode' => 'or',
                    'licenses' => self::splitConjunction($expression, 'OR'),
                ];
            }

            // Mixed AND/OR without a full SPDX parser → keep as one token (likely review).
            if (self::containsConjunction($expression, 'AND') || self::containsConjunction($expression, 'OR')) {
                return ['mode' => 'or', 'licenses' => [$expression]];
            }

            return ['mode' => 'or', 'licenses' => [$expression]];
        }

        // Multiple discrete entries (Composer license array) ⇒ OR.
        return ['mode' => 'or', 'licenses' => $licenses];
    }

    /**
     * Classify a package from its license list or SPDX expression tokens.
     *
     * OR (dual-license / Composer array): Compatible if any option is compatible;
     * Incompatible only when every option is incompatible.
     * AND: Compatible only if every license is compatible; Incompatible if any is.
     *
     * @param  list<string>  $licenses
     */
    public static function classify(array $licenses): string
    {
        $parsed = self::parseExpression($licenses);
        $normalized = array_values(array_unique(array_filter(
            array_map(fn (string $license): string => self::normalize($license), $parsed['licenses']),
            fn (string $license): bool => $license !== ''
        )));

        if ($normalized === []) {
            return self::REQUIRES_REVIEW;
        }

        if ($parsed['mode'] === 'and') {
            return self::classifyConjunction($normalized);
        }

        return self::classifyDisjunction($normalized);
    }

    /**
     * @param  list<string>  $normalized
     */
    private static function classifyDisjunction(array $normalized): string
    {
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

    /**
     * AND: must satisfy every license — worst status wins.
     *
     * @param  list<string>  $normalized
     */
    private static function classifyConjunction(array $normalized): string
    {
        $statuses = [];

        foreach ($normalized as $license) {
            if (in_array($license, self::INCOMPATIBLE_LICENSES, true)) {
                $statuses[] = self::INCOMPATIBLE;

                continue;
            }

            if (in_array($license, self::COMPATIBLE_LICENSES, true)) {
                $statuses[] = self::COMPATIBLE;

                continue;
            }

            $statuses[] = self::REQUIRES_REVIEW;
        }

        if (in_array(self::INCOMPATIBLE, $statuses, true)) {
            return self::INCOMPATIBLE;
        }

        if (in_array(self::REQUIRES_REVIEW, $statuses, true)) {
            return self::REQUIRES_REVIEW;
        }

        return self::COMPATIBLE;
    }

    private static function stripOuterParens(string $expression): string
    {
        $expression = trim($expression);

        while (
            str_starts_with($expression, '(')
            && str_ends_with($expression, ')')
            && self::balancedParens(substr($expression, 1, -1))
        ) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    private static function balancedParens(string $value): bool
    {
        $depth = 0;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char = $value[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    private static function containsConjunction(string $expression, string $op): bool
    {
        return (bool) preg_match('/(^|[\s)])'.preg_quote($op, '/').'([\s(]|$)/i', $expression);
    }

    /**
     * @return list<string>
     */
    private static function splitConjunction(string $expression, string $op): array
    {
        $parts = preg_split('/\s+'.preg_quote($op, '/').'\s+/i', $expression);

        if (! is_array($parts)) {
            return [trim($expression)];
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => self::stripOuterParens(trim($part)),
            $parts
        ), static fn (string $part): bool => $part !== ''));
    }
}
