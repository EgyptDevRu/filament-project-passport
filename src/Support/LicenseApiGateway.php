<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * Resolves the EgyptDev Studio API URL at runtime.
 *
 * This is obfuscation against casual inspection — not cryptographic secrecy.
 */
final class LicenseApiGateway
{
    /**
     * License-check.
     */
    public static function licenseCheckUrl(): string
    {
        return rtrim(self::origin(), '/').self::path();
    }

    /**
     * API origin.
     */
    public static function origin(): string
    {
        return self::unpack([
            0x32, 0x2F, 0x28, 0x2D, 0x2D, 0x65, 0x4F, 0x4E,
            0x07, 0x0D, 0x4A, 0x00, 0x01, 0x1E, 0x18, 0x1D,
            0x0E, 0x0E, 0x1A, 0x43, 0x1C, 0x1A,
        ], 0x5A);
    }

    private static function path(): string
    {
        return self::unpack([
            0x75, 0x3A, 0x2C, 0x34, 0x71, 0x29, 0x51, 0x4E,
            0x0E, 0x0A, 0x07, 0x00, 0x08, 0x14, 0x0D, 0x44,
            0x09, 0x03, 0x09, 0x0E, 0x05,
        ], 0x5A);
    }

    /**
     * @param  list<int>  $bytes
     */
    private static function unpack(array $bytes, int $key): string
    {
        $out = '';

        foreach ($bytes as $i => $byte) {
            $out .= chr($byte ^ (($key + $i) % 256));
        }

        return $out;
    }
}
