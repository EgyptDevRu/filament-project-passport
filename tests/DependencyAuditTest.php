<?php

use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use Illuminate\Support\Facades\Cache;

it('parses composer outdated json into package rows', function () {
    $auditor = app(ComposerDependencyAuditor::class);

    $rows = $auditor->parseOutdated([
        'installed' => [
            [
                'name' => 'laravel/framework',
                'version' => 'v11.0.0',
                'latest' => 'v12.1.0',
                'latest-status' => 'update-possible',
            ],
            [
                'name' => 'filament/filament',
                'version' => 'v3.2.0',
                'latest' => 'v3.3.0',
                'latest-status' => 'semver-safe-update',
            ],
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('filament/filament')
        ->and($rows[1]['latest'])->toBe('v12.1.0');
});

it('decodes composer json even when warnings or trailing text surround it', function () {
    $auditor = app(ComposerDependencyAuditor::class);

    $raw = <<<'JSON'
Deprecation Notice: something {not json}
{
    "installed": [
        {
            "name": "foo/bar",
            "version": "1.0.0",
            "latest": "1.1.0",
            "latest-status": "semver-safe-update"
        }
    ]
}
Done in 1.23s
JSON;

    $decoded = $auditor->decodeJsonObject($raw);

    expect($decoded)->toBeArray()
        ->and($decoded['installed'])->toHaveCount(1)
        ->and($auditor->parseOutdated($decoded))->toHaveCount(1);
});

it('parses composer audit advisories into flat rows', function () {
    $auditor = app(ComposerDependencyAuditor::class);

    $rows = $auditor->parseAdvisories([
        'advisories' => [
            'vendor/package' => [
                [
                    'title' => 'Example advisory',
                    'cve' => 'CVE-2024-0001',
                    'link' => 'https://example.com/advisory',
                    'affectedVersions' => '<1.2.3',
                ],
            ],
        ],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['package'])->toBe('vendor/package')
        ->and($rows[0]['cve'])->toBe('CVE-2024-0001')
        ->and($rows[0]['title'])->toBe('Example advisory')
        ->and($rows[0]['link'])->toBe('https://example.com/advisory');
});

it('drops non-http advisory links', function () {
    $auditor = app(ComposerDependencyAuditor::class);

    $rows = $auditor->parseAdvisories([
        'advisories' => [
            'vendor/package' => [
                [
                    'title' => 'Bad link',
                    'link' => 'javascript:alert(1)',
                ],
                [
                    'title' => 'Data link',
                    'link' => 'data:text/html,<script>alert(1)</script>',
                ],
            ],
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['link'])->toBeNull()
        ->and($rows[1]['link'])->toBeNull();
});

it('caches dependency audit payloads while fresh', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    $payload = [
        'outdated_count' => 2,
        'advisory_count' => 1,
        'outdated' => [
            ['name' => 'a/b', 'version' => '1.0.0', 'latest' => '1.1.0', 'status' => 'update-possible'],
        ],
        'advisories' => [
            ['package' => 'a/b', 'title' => 'Issue', 'cve' => null, 'link' => null, 'affected_versions' => null],
        ],
        'laravel' => [
            'installed' => 'v11.0.0',
            'latest' => 'v12.0.0',
            'up_to_date' => false,
            'installed_flag' => true,
        ],
        'filament' => [
            'installed' => 'v3.0.0',
            'latest' => 'v3.0.0',
            'up_to_date' => true,
            'installed_flag' => true,
        ],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ];

    Cache::put($auditor->cacheKey(), $payload, 86400);

    $cached = $auditor->audit();

    expect($cached['from_cache'])->toBeTrue()
        ->and($cached['outdated_count'])->toBe(2)
        ->and($cached['advisory_count'])->toBe(1)
        ->and($cached['laravel']['installed'])->toBe('v11.0.0')
        ->and($auditor->shouldRefresh())->toBeFalse();
});

it('requires dependency audit refresh when cache is older than seven days', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->subDays(7)->toIso8601String(),
        'error' => null,
    ], 86400);

    expect($auditor->shouldRefresh())->toBeTrue();
});
