<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * Internal license-check error codes shown on the Status page.
 *
 * Contact: info@egyptdev.ru
 */
final class LicenseErrorCode
{
    public const string HTTP_401 = 'PP-HTTP-401';

    public const string HTTP_403 = 'PP-HTTP-403';

    public const string HTTP_404 = 'PP-HTTP-404';

    public const string HTTP_429 = 'PP-HTTP-429';

    public const string HTTP_500 = 'PP-HTTP-500';

    public const string HTTP_502 = 'PP-HTTP-502';

    public const string HTTP_503 = 'PP-HTTP-503';

    public const string HTTP_504 = 'PP-HTTP-504';

    public const string HTTP_OTHER = 'PP-HTTP-OTHER';

    public const string CF_520 = 'PP-CF-520';

    public const string CF_521 = 'PP-CF-521';

    public const string CF_522 = 'PP-CF-522';

    public const string CF_523 = 'PP-CF-523';

    public const string CF_524 = 'PP-CF-524';

    public const string CF_525 = 'PP-CF-525';

    public const string CF_526 = 'PP-CF-526';

    public const string CF_530 = 'PP-CF-530';

    public const string CF_BLOCK = 'PP-CF-BLOCK';

    public const string DNS_NXDOMAIN = 'PP-DNS-NXDOMAIN';

    public const string NET_TIMEOUT = 'PP-NET-TIMEOUT';

    public const string NET_CONNECTION = 'PP-NET-CONNECTION';

    public const string JSON_INVALID = 'PP-JSON-INVALID';

    public const string JSON_EMPTY = 'PP-JSON-EMPTY';

    public const string UNKNOWN = 'PP-UNKNOWN';

    /**
     * @return array<string, string> code => short description
     */
    public static function catalog(): array
    {
        return [
            self::HTTP_401 => 'License API returned HTTP 401 Unauthorized.',
            self::HTTP_403 => 'License API returned HTTP 403 Forbidden.',
            self::HTTP_404 => 'License API endpoint returned HTTP 404 Not Found.',
            self::HTTP_429 => 'License API rate-limited the request (HTTP 429).',
            self::HTTP_500 => 'License API returned HTTP 500 Internal Server Error.',
            self::HTTP_502 => 'License API returned HTTP 502 Bad Gateway.',
            self::HTTP_503 => 'License API returned HTTP 503 Service Unavailable.',
            self::HTTP_504 => 'License API returned HTTP 504 Gateway Timeout.',
            self::HTTP_OTHER => 'License API returned an unexpected non-success HTTP status.',
            self::CF_520 => 'Cloudflare error 520 (web server returned an unknown error).',
            self::CF_521 => 'Cloudflare error 521 (web server is down).',
            self::CF_522 => 'Cloudflare error 522 (connection timed out).',
            self::CF_523 => 'Cloudflare error 523 (origin is unreachable).',
            self::CF_524 => 'Cloudflare error 524 (a timeout occurred).',
            self::CF_525 => 'Cloudflare error 525 (SSL handshake failed).',
            self::CF_526 => 'Cloudflare error 526 (invalid SSL certificate).',
            self::CF_530 => 'Cloudflare error 530 (origin DNS / connectivity issue).',
            self::CF_BLOCK => 'Cloudflare challenge / WAF block intercepted the license check.',
            self::DNS_NXDOMAIN => 'DNS lookup failed (NXDOMAIN / host could not be resolved).',
            self::NET_TIMEOUT => 'Network timeout while contacting the license API.',
            self::NET_CONNECTION => 'Network connection to the license API failed.',
            self::JSON_INVALID => 'License API response was not valid JSON.',
            self::JSON_EMPTY => 'License API returned empty or non-object JSON.',
            self::UNKNOWN => 'Unexpected license-check failure.',
        ];
    }

    public static function description(string $code): string
    {
        return self::catalog()[$code] ?? self::catalog()[self::UNKNOWN];
    }

    /**
     * @param  array<string, mixed>  $license
     */
    public static function isUndefinedStatus(array $license): bool
    {
        return (bool) ($license['request_failed'] ?? false);
    }
}
