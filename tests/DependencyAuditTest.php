<?php

use EgyptDevRu\FilamentProjectPassport\Jobs\RefreshDependencyAuditJob;
use EgyptDevRu\FilamentProjectPassport\Pages\DependencyAuditPage;
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

it('returns null from cachedPayload when the cache is cold', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    expect($auditor->cachedPayload())->toBeNull();
});

it('reads cachedPayload without requiring a composer scan', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'outdated_count' => 1,
        'advisory_count' => 0,
        'outdated' => [
            ['name' => 'a/b', 'version' => '1.0.0', 'latest' => '1.1.0', 'status' => 'update-possible'],
        ],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    $cached = $auditor->cachedPayload();

    expect($cached)->toBeArray()
        ->and($cached['from_cache'])->toBeTrue()
        ->and($cached['outdated_count'])->toBe(1);
});

it('marks background refresh running without blocking on a cold cache', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    expect($auditor->isRefreshRunning())->toBeFalse()
        ->and($auditor->dispatchBackgroundRefresh())->toBeTrue()
        ->and($auditor->isRefreshRunning())->toBeTrue()
        // Second call must not start another overlapping job.
        ->and($auditor->dispatchBackgroundRefresh())->toBeFalse();
});

it('hydrates dependency audit page from cache without scanning', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'outdated_count' => 3,
        'advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->scanning)->toBeFalse()
        ->and($page->outdatedCount())->toBe(3);
});

it('mounts empty placeholder immediately when dependency cache is cold', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->scanning)->toBeTrue()
        ->and($page->audit['outdated'])->toBe([])
        ->and($page->audit['checked_at'])->toBeNull()
        // mount must not start Composer / mark running — that happens on wire:init.
        ->and($auditor->isRefreshRunning())->toBeFalse();
});

it('starts background refresh on loadPageData without waiting for composer', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->loadPageData();

    expect($page->scanning)->toBeTrue()
        ->and($page->outdatedCount())->toBe(0)
        ->and($auditor->isRefreshRunning())->toBeTrue();
});

it('stays non-blocking when a dependency refresh is already running', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();
    $auditor->markRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->loadPageData();

    expect($page->scanning)->toBeTrue()
        ->and($page->audit['checked_at'])->toBeNull()
        ->and($auditor->isRefreshRunning())->toBeTrue()
        ->and(Cache::get($auditor->cacheKey()))->toBeNull();
});

it('surfaces a completed error payload to the page via poll-style loadPageData', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => 'Composer could not finish outdated (git credentials).',
    ], 600);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->scanning)->toBeFalse()
        ->and($page->audit['error'])->toContain('git credentials')
        ->and($auditor->cachedPayload())->toBeNull()
        ->and($auditor->completedPayload()['error'])->toContain('git credentials');
});

it('stops scanning when the background worker dies without writing cache', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->loadPageData();

    expect($page->scanning)->toBeTrue()
        ->and($page->refreshRequested)->toBeTrue();

    // Simulate queue/worker crash: lock cleared, no payload written.
    $auditor->clearRefreshRunning();

    $page->pollPageData();

    expect($page->scanning)->toBeFalse()
        ->and($page->audit['error'])->toContain('did not complete')
        ->and($auditor->completedPayload()['error'])->toContain('did not complete');
});

it('stops scanning after the front-end scan timeout when still updating', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();
    $auditor->markRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->scanning = true;
    $page->refreshRequested = true;

    $page->failScanTimeout();

    expect($page->scanning)->toBeFalse()
        ->and($page->audit['error'])->toContain('3 minutes')
        ->and($page->audit['error'])->toContain('queue worker')
        ->and($auditor->isRefreshRunning())->toBeFalse();
});

it('records a failure payload when the refresh job reports failure', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->markRefreshRunning();

    $job = new RefreshDependencyAuditJob(force: true);
    $job->failed(new RuntimeException('Maximum execution time of 180 seconds exceeded'));

    expect($auditor->isRefreshRunning())->toBeFalse()
        ->and($auditor->completedPayload()['error'])->toContain('Maximum execution time');
});

it('decodes composer json when xdebug warnings prefix the output', function () {
    $auditor = app(ComposerDependencyAuditor::class);

    $raw = <<<'TXT'
Xdebug: [Step Debug] Time-out connecting to debugging client, waited: 200 ms.
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
TXT;

    expect($auditor->decodeJsonObject($raw))->toBeArray()
        ->and($auditor->decodeJsonObject($raw)['installed'])->toHaveCount(1);
});
