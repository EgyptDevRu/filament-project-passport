<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use EgyptDevRu\FilamentProjectPassport\Support\LicenseApiGateway;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * License / developer-info API client.
 *
 * SECURITY: The API URL is resolved via LicenseApiGateway (not config/.env).
 */
final class LicenseApiClient
{
    /** Successful responses stay warm for 12 hours. */
    private const int CACHE_TTL_SECONDS = 12 * 60 * 60;

    /** Failed / unreachable responses are cached briefly to avoid hammering the API. */
    private const int FAILURE_CACHE_TTL_SECONDS = 15 * 60;

    private const int REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Fetch (and cache) license / developer information for the current host.
     *
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        $host = $this->resolveHost();
        $cacheKey = $this->cacheKey($host);

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->withMeta($cached, fromCache: true);
        }

        $result = $this->requestFromApi($host);

        $ttl = ($result['request_failed'] ?? false)
            ? self::FAILURE_CACHE_TTL_SECONDS
            : self::CACHE_TTL_SECONDS;

        Cache::put($cacheKey, $this->cacheablePayload($result), $ttl);

        return $this->withMeta($result, fromCache: false);
    }

    /**
     * Bust the cached API response for the current host and re-fetch.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->forgetCache();

        return $this->fetch();
    }

    public function isOfficial(): bool
    {
        $result = $this->fetch();

        return ! LicenseErrorCode::isUndefinedStatus($result)
            && (bool) ($result['is_official'] ?? false);
    }

    /**
     * Documentation is available only for a successful official license check.
     */
    public function allowsDocumentation(): bool
    {
        return $this->isOfficial();
    }

    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey($this->resolveHost()));
    }

    public function cacheKey(?string $host = null): string
    {
        $host ??= $this->resolveHost();

        return 'filament-project-passport.license.'.md5(strtolower($host));
    }

    public function endpoint(): string
    {
        return LicenseApiGateway::licenseCheckUrl();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFromApi(string $host): array
    {
        $endpoint = $this->endpoint();

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->asJson()
                ->withHeaders($this->requestHeaders($host))
                ->post($endpoint, [
                    'host' => $host,
                    'domain' => $host,
                ]);

            $status = $response->status();
            $rawBody = (string) $response->body();
            $json = $this->decodeJsonBody($rawBody);

            if ($response->failed() || $status !== 200) {
                $errorCode = $this->classifyHttpFailure($status, $rawBody);

                return $this->undefinedFallback(
                    errorCode: $errorCode,
                    error: LicenseErrorCode::description($errorCode)." (HTTP {$status})",
                    httpStatus: $status,
                    responseBody: $rawBody,
                    endpoint: $endpoint,
                    sentHost: $host,
                );
            }

            if ($json === null) {
                return $this->undefinedFallback(
                    errorCode: LicenseErrorCode::JSON_INVALID,
                    error: LicenseErrorCode::description(LicenseErrorCode::JSON_INVALID),
                    httpStatus: $status,
                    responseBody: $rawBody,
                    endpoint: $endpoint,
                    sentHost: $host,
                );
            }

            if ($json === []) {
                return $this->undefinedFallback(
                    errorCode: LicenseErrorCode::JSON_EMPTY,
                    error: LicenseErrorCode::description(LicenseErrorCode::JSON_EMPTY),
                    httpStatus: $status,
                    responseBody: $rawBody,
                    endpoint: $endpoint,
                    sentHost: $host,
                );
            }

            return array_merge($this->normalizePayload($json), [
                'request_failed' => false,
                'error_code' => null,
                'error' => null,
                'http_status' => $status,
                'response_body' => null,
                'endpoint' => $endpoint,
                'sent_host' => $host,
            ]);
        } catch (Throwable $exception) {
            $errorCode = $this->classifyException($exception);

            return $this->undefinedFallback(
                errorCode: $errorCode,
                error: LicenseErrorCode::description($errorCode).': '.$exception->getMessage(),
                httpStatus: null,
                responseBody: null,
                endpoint: $endpoint,
                sentHost: $host,
            );
        }
    }

    private function classifyHttpFailure(int $status, string $body): string
    {
        if ($this->looksLikeCloudflare($status, $body)) {
            return match ($status) {
                520 => LicenseErrorCode::CF_520,
                521 => LicenseErrorCode::CF_521,
                522 => LicenseErrorCode::CF_522,
                523 => LicenseErrorCode::CF_523,
                524 => LicenseErrorCode::CF_524,
                525 => LicenseErrorCode::CF_525,
                526 => LicenseErrorCode::CF_526,
                530 => LicenseErrorCode::CF_530,
                default => LicenseErrorCode::CF_BLOCK,
            };
        }

        return match ($status) {
            401 => LicenseErrorCode::HTTP_401,
            403 => LicenseErrorCode::HTTP_403,
            404 => LicenseErrorCode::HTTP_404,
            429 => LicenseErrorCode::HTTP_429,
            500 => LicenseErrorCode::HTTP_500,
            502 => LicenseErrorCode::HTTP_502,
            503 => LicenseErrorCode::HTTP_503,
            504 => LicenseErrorCode::HTTP_504,
            default => LicenseErrorCode::HTTP_OTHER,
        };
    }

    private function looksLikeCloudflare(int $status, string $body): bool
    {
        if (in_array($status, [520, 521, 522, 523, 524, 525, 526, 530], true)) {
            return true;
        }

        $haystack = Str::lower($body);

        return str_contains($haystack, 'cloudflare')
            || str_contains($haystack, 'cf-ray')
            || str_contains($haystack, 'cf-error')
            || str_contains($haystack, 'attention required! | cloudflare')
            || str_contains($haystack, '__cf_chl');
    }

    private function classifyException(Throwable $exception): string
    {
        $message = Str::lower($exception->getMessage());

        if (
            str_contains($message, 'could not resolve host')
            || str_contains($message, 'name or service not known')
            || str_contains($message, 'getaddrinfo')
            || str_contains($message, 'nodename nor servname')
            || str_contains($message, 'nxdomain')
            || str_contains($message, 'curl error 6')
        ) {
            return LicenseErrorCode::DNS_NXDOMAIN;
        }

        if (
            str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')
        ) {
            return LicenseErrorCode::NET_TIMEOUT;
        }

        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return LicenseErrorCode::NET_CONNECTION;
        }

        return LicenseErrorCode::UNKNOWN;
    }

    /**
     * Browser-like headers so hosting WAF / anti-DDoS layers do not treat
     * the license check as a bare HTTP client probe.
     *
     * @return array<string, string>
     */
    private function requestHeaders(string $host): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'X-Application-Host' => $host,
            'X-Requested-With' => 'FilamentProjectPassport',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(string $rawBody): ?array
    {
        $rawBody = preg_replace('/^\xEF\xBB\xBF/', '', $rawBody) ?? $rawBody;

        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveHost(): string
    {
        try {
            $host = request()->getHost();
        } catch (Throwable) {
            $host = null;
        }

        if (blank($host) || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $appUrl = (string) config('app.url', '');
            $parsed = parse_url($appUrl, PHP_URL_HOST);

            if (is_string($parsed) && $parsed !== '') {
                return $parsed;
            }
        }

        return is_string($host) && $host !== '' ? $host : 'unknown.host';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     is_official: bool,
     *     developer_name: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     support_warranty_until: ?string,
     *     extended_support_warranty_until: ?string,
     *     client_name: ?string,
     *     project_name: ?string,
     * }
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'is_official' => $this->toBool($payload['is_official'] ?? false),
            'developer_name' => $this->nullableString($payload['developer_name'] ?? null),
            'email' => $this->nullableString($payload['email'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'support_warranty_until' => $this->nullableString($payload['support_warranty_until'] ?? null),
            'extended_support_warranty_until' => $this->nullableString($payload['extended_support_warranty_until'] ?? null),
            'client_name' => $this->nullableString($payload['client_name'] ?? null),
            'project_name' => $this->nullableString($payload['project_name'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function cacheablePayload(array $result): array
    {
        return [
            'is_official' => (bool) ($result['is_official'] ?? false),
            'developer_name' => $result['developer_name'] ?? null,
            'email' => $result['email'] ?? null,
            'phone' => $result['phone'] ?? null,
            'support_warranty_until' => $result['support_warranty_until'] ?? null,
            'extended_support_warranty_until' => $result['extended_support_warranty_until'] ?? null,
            'client_name' => $result['client_name'] ?? null,
            'project_name' => $result['project_name'] ?? null,
            'request_failed' => (bool) ($result['request_failed'] ?? false),
            'error_code' => $result['error_code'] ?? null,
            'error' => $result['error'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'response_body' => isset($result['response_body']) ? Str::limit((string) $result['response_body'], 1000) : null,
            'endpoint' => $result['endpoint'] ?? $this->endpoint(),
            'sent_host' => $result['sent_host'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withMeta(array $payload, bool $fromCache): array
    {
        return array_merge($this->normalizePayload($payload), [
            'from_cache' => $fromCache,
            'request_failed' => (bool) ($payload['request_failed'] ?? false),
            'error_code' => $payload['error_code'] ?? null,
            'error' => $payload['error'] ?? null,
            'http_status' => $payload['http_status'] ?? null,
            'response_body' => $payload['response_body'] ?? null,
            'endpoint' => $payload['endpoint'] ?? $this->endpoint(),
            'sent_host' => $payload['sent_host'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function undefinedFallback(
        string $errorCode,
        ?string $error = null,
        ?int $httpStatus = null,
        ?string $responseBody = null,
        ?string $endpoint = null,
        ?string $sentHost = null,
    ): array {
        return [
            'is_official' => false,
            'developer_name' => null,
            'email' => null,
            'phone' => null,
            'support_warranty_until' => null,
            'extended_support_warranty_until' => null,
            'client_name' => null,
            'project_name' => null,
            'request_failed' => true,
            'error_code' => $errorCode,
            'error' => $error,
            'http_status' => $httpStatus,
            'response_body' => $responseBody !== null ? Str::limit($responseBody, 1000) : null,
            'endpoint' => $endpoint ?? $this->endpoint(),
            'sent_host' => $sentHost,
        ];
    }
}
