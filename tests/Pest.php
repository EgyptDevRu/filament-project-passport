<?php

use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use EgyptDevRu\FilamentProjectPassport\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $payload
 */
function passportPutSignedCache(string $key, array $payload, mixed $ttl = 86400): void
{
    Cache::put($key, SignedCachePayload::seal($payload), $ttl);
}
