<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * HMAC-signed cache envelopes so admins cannot forge checked_at / rate-limit
 * values in Redis/DB by editing the cached JSON/array without APP_KEY.
 */
final class SignedCachePayload
{
    private const string SIG_KEY = '_passport_sig';

    private const string AT_KEY = '_passport_signed_at';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function seal(array $payload): array
    {
        unset($payload[self::SIG_KEY], $payload[self::AT_KEY]);

        $signedAt = now()->toIso8601String();
        $payload[self::AT_KEY] = $signedAt;
        $payload[self::SIG_KEY] = self::signature($payload, $signedAt);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null Null when missing or tampered.
     */
    public static function open(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        $sig = $cached[self::SIG_KEY] ?? null;
        $signedAt = $cached[self::AT_KEY] ?? null;

        if (! is_string($sig) || $sig === '' || ! is_string($signedAt) || $signedAt === '') {
            return null;
        }

        $payload = $cached;
        unset($payload[self::SIG_KEY]);

        $expected = self::signature($payload, $signedAt);

        if (! hash_equals($expected, $sig)) {
            return null;
        }

        unset($cached[self::SIG_KEY], $cached[self::AT_KEY]);

        return $cached;
    }

    /**
     * @param  array<string, mixed>  $payload  Must still contain AT_KEY; SIG_KEY absent.
     */
    private static function signature(array $payload, string $signedAt): string
    {
        $payload[self::AT_KEY] = $signedAt;
        ksort($payload);

        /** @var array<string, mixed> $normalized */
        $normalized = self::normalize($payload);

        $json = json_encode($normalized, JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $json, self::key());
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key', '');

        if ($appKey === '') {
            return 'filament-project-passport-unsigned';
        }

        return $appKey;
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
