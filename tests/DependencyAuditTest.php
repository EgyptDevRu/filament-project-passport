<?php

use EgyptDevRu\FilamentProjectPassport\Jobs\RefreshDependencyAuditJob;
use EgyptDevRu\FilamentProjectPassport\Pages\DependencyAuditPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\NpmRuntime;
use EgyptDevRu\FilamentProjectPassport\Support\PhpRuntimeStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

afterEach(function () {
    NpmRuntime::fake(null);
    dependencyAuditCleanupNpmManifest();
});

function dependencyAuditSeedNpmManifest(): void
{
    File::put(base_path('package.json'), json_encode([
        'name' => 'host-app',
        'private' => true,
        'dependencies' => [
            'left-pad' => '1.3.0',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    File::put(base_path('package-lock.json'), json_encode([
        'name' => 'host-app',
        'lockfileVersion' => 3,
        'packages' => [
            '' => [
                'name' => 'host-app',
                'dependencies' => [
                    'left-pad' => '1.3.0',
                ],
            ],
            'node_modules/left-pad' => [
                'version' => '1.3.0',
                'license' => 'MIT',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}

function dependencyAuditCleanupNpmManifest(): void
{
    foreach ([base_path('package.json'), base_path('package-lock.json')] as $path) {
        if (File::isFile($path)) {
            File::delete($path);
        }
    }
}

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

    passportPutSignedCache($auditor->cacheKey(), $payload, 86400);

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

    passportPutSignedCache($auditor->cacheKey(), [
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

    passportPutSignedCache($auditor->cacheKey(), [
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
    app(NpmDependencyAuditor::class)->forgetCache();
    dependencyAuditCleanupNpmManifest();

    passportPutSignedCache($auditor->cacheKey(), [
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

it('keeps scanning and refreshes when composer is cached but npm is still cold', function () {
    NpmRuntime::fake(true);
    dependencyAuditSeedNpmManifest();

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();
    $composer->clearRefreshRunning();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 1,
        'advisory_count' => 0,
        'high_advisory_count' => 0,
        'outdated' => [
            ['name' => 'a/b', 'version' => '1.0.0', 'latest' => '1.1.0', 'status' => 'update-possible'],
        ],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->scanning)->toBeTrue()
        ->and($page->outdatedCount())->toBe(1)
        ->and($page->npmOutdatedCount())->toBe(0)
        ->and($composer->isRefreshRunning())->toBeFalse();

    $page->loadPageData();

    expect($page->scanning)->toBeTrue()
        ->and($page->refreshRequested)->toBeTrue()
        ->and($composer->isRefreshRunning())->toBeTrue()
        ->and($page->showsNpmDependencyResults())->toBeTrue()
        ->and($page->showsNpmNodeUnavailableNotice())->toBeFalse();
});

it('stays composer-only when node exists but the host has no package.json', function () {
    NpmRuntime::fake(true);
    dependencyAuditCleanupNpmManifest();

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();
    $composer->clearRefreshRunning();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 2,
        'advisory_count' => 0,
        'high_advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->loadPageData();

    $payload = $npm->audit(fresh: true);

    expect($npm->nodeIsAvailable())->toBeTrue()
        ->and($npm->hasPackageManifest())->toBeFalse()
        ->and($npm->isRunnable())->toBeFalse()
        ->and($npm->needsBackgroundScan())->toBeFalse()
        ->and($npm->shouldRefresh())->toBeFalse()
        ->and($page->scanning)->toBeFalse()
        ->and($page->outdatedCount())->toBe(2)
        ->and($page->showsNpmDependencyResults())->toBeFalse()
        ->and($page->showsNpmNodeUnavailableNotice())->toBeFalse()
        ->and($payload['available'])->toBeFalse()
        ->and($payload['skipped'])->toBeFalse()
        ->and($payload['error'])->toBeNull()
        ->and($payload['outdated'])->toBe([])
        ->and($payload['message'])->toContain('package.json');
});

it('skips npm dependency audit and hides npm blocks when node is unavailable', function () {
    NpmRuntime::fake(false);
    dependencyAuditSeedNpmManifest();

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();
    $composer->clearRefreshRunning();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 1,
        'advisory_count' => 0,
        'high_advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($npm->isRunnable())->toBeFalse()
        ->and($npm->needsBackgroundScan())->toBeFalse()
        ->and($npm->shouldRefresh())->toBeFalse()
        ->and($page->scanning)->toBeFalse()
        ->and($page->showsNpmDependencyResults())->toBeFalse()
        ->and($page->showsNpmNodeUnavailableNotice())->toBeTrue()
        ->and($page->npmAudit['skipped'])->toBeTrue()
        ->and($page->npmAudit['message'])->toContain('Node.js');

    $payload = $npm->audit(fresh: true);

    expect($payload['skipped'])->toBeTrue()
        ->and($payload['outdated_count'])->toBe(0)
        ->and($payload['error'])->toBeNull();
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

    passportPutSignedCache($auditor->cacheKey(), [
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

it('keeps scanning on front-end timeout while the background refresh lock is held', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();
    $auditor->markRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->scanning = true;
    $page->refreshRequested = true;

    $page->failScanTimeout();

    expect($page->scanning)->toBeTrue()
        ->and($auditor->completedPayload())->toBeNull()
        ->and($auditor->isRefreshRunning())->toBeTrue();
});

it('stops scanning after the front-end scan timeout when still updating', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();
    $auditor->clearRefreshRunning();

    $page = app(DependencyAuditPage::class);
    $page->mount();
    $page->scanning = true;
    $page->refreshRequested = true;

    $page->failScanTimeout();

    expect($page->scanning)->toBeFalse()
        ->and($page->audit['error'])->toContain('did not finish')
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

it('persists npm failure when the job dies after composer already succeeded', function () {
    NpmRuntime::fake(true);
    dependencyAuditSeedNpmManifest();

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();
    $composer->markRefreshRunning();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    expect($composer->completedPayload())->toBeArray()
        ->and($npm->needsBackgroundScan())->toBeTrue();

    $job = new RefreshDependencyAuditJob(force: true);
    $job->failed(new RuntimeException('Maximum execution time of 660 seconds exceeded'));

    expect($composer->completedPayload()['error'] ?? null)->toBeNull()
        ->and($npm->needsBackgroundScan())->toBeFalse()
        ->and($npm->completedPayload()['error'])->toContain('Maximum execution time')
        ->and($composer->isRefreshRunning())->toBeFalse();
});

it('budgets queue timeout for composer plus npm scans', function () {
    expect(RefreshDependencyAuditJob::TIMEOUT_SECONDS)
        ->toBeGreaterThan(ComposerDependencyAuditor::MAX_SCAN_SECONDS)
        ->and(RefreshDependencyAuditJob::TIMEOUT_SECONDS)
        ->toBeGreaterThanOrEqual(
            ComposerDependencyAuditor::MAX_SCAN_SECONDS + (int) (NpmDependencyAuditor::MAX_SCAN_SECONDS) + 60
        );
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

it('parses npm outdated json into package rows', function () {
    $auditor = app(NpmDependencyAuditor::class);

    $rows = $auditor->parseOutdated([
        'lodash' => [
            'current' => '4.17.20',
            'wanted' => '4.17.21',
            'latest' => '4.17.21',
        ],
        'left-pad' => [
            'current' => '1.0.0',
            'wanted' => '1.3.0',
            'latest' => '1.3.0',
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('left-pad')
        ->and($rows[1]['name'])->toBe('lodash')
        ->and($rows[1]['latest'])->toBe('4.17.21');
});

it('parses npm audit vulnerabilities into advisory rows', function () {
    $auditor = app(NpmDependencyAuditor::class);

    $rows = $auditor->parseAdvisories([
        'vulnerabilities' => [
            'minimist' => [
                'name' => 'minimist',
                'severity' => 'high',
                'range' => '<1.2.6',
                'via' => [
                    [
                        'title' => 'Prototype Pollution in minimist',
                        'url' => 'https://github.com/advisories/GHSA-example',
                        'cve' => 'CVE-2021-44906',
                    ],
                ],
            ],
            'dev-only' => [
                'severity' => 'low',
                'range' => '*',
                'via' => ['transitive'],
            ],
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['package'])->toBe('dev-only')
        ->and($rows[1]['package'])->toBe('minimist')
        ->and($rows[1]['severity'])->toBe('high')
        ->and($rows[1]['cve'])->toBe('CVE-2021-44906')
        ->and($rows[1]['link'])->toBe('https://github.com/advisories/GHSA-example')
        ->and($auditor->highAdvisoryCount($rows))->toBe(1);
});

it('returns empty npm dependency payload during unit tests without calling npm', function () {
    NpmRuntime::fake(true);
    dependencyAuditSeedNpmManifest();

    $auditor = app(NpmDependencyAuditor::class);
    $auditor->forgetCache();

    $payload = $auditor->audit(fresh: true);

    expect($payload['outdated'])->toBe([])
        ->and($payload['advisories'])->toBe([])
        ->and($payload['error'])->toBeNull()
        ->and($payload['available'])->toBeTrue()
        ->and($payload['checked_at'])->not->toBeNull();
});

it('requires npm dependency audit refresh when cache is older than seven days', function () {
    NpmRuntime::fake(true);
    dependencyAuditSeedNpmManifest();

    $auditor = app(NpmDependencyAuditor::class);
    $auditor->forgetCache();

    passportPutSignedCache($auditor->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'high_advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->subDays(7)->toIso8601String(),
        'error' => null,
        'available' => true,
    ], 86400);

    expect($auditor->shouldRefresh())->toBeTrue();
});

it('hydrates npm dependency data on the dependency audit page from cache', function () {
    NpmRuntime::fake(true);
    dependencyAuditSeedNpmManifest();

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'high_advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    passportPutSignedCache($npm->cacheKey(), [
        'outdated_count' => 2,
        'advisory_count' => 1,
        'high_advisory_count' => 1,
        'outdated' => [
            ['name' => 'lodash', 'version' => '4.0.0', 'latest' => '4.17.21', 'status' => 'update-possible'],
        ],
        'advisories' => [
            ['package' => 'lodash', 'title' => 'Issue', 'severity' => 'high', 'cve' => null, 'link' => null, 'affected_versions' => null],
        ],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
        'available' => true,
        'runnable' => true,
        'node_available' => true,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->scanning)->toBeFalse()
        ->and($page->npmOutdatedCount())->toBe(2)
        ->and($page->npmAdvisoryCount())->toBe(1)
        ->and($page->npmHighAdvisoryCount())->toBe(1)
        ->and($page->showsNpmDependencyResults())->toBeTrue()
        ->and($page->shouldEscalateHighAdvisories())->toBeFalse();
});

it('escalates when high advisories remain after a successful check older than seven days', function () {
    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 1,
        'high_advisory_count' => 1,
        'outdated' => [],
        'advisories' => [
            ['package' => 'a/b', 'title' => 'High issue', 'severity' => 'high', 'cve' => null, 'link' => null, 'affected_versions' => null],
        ],
        'checked_at' => now()->subDays(8)->toIso8601String(),
        'error' => null,
    ], 86400);

    $page = app(DependencyAuditPage::class);
    $page->mount();

    expect($page->highAdvisoryCount())->toBe(1)
        ->and($page->shouldEscalateHighAdvisories())->toBeTrue();
});

it('discovers flyenv versioned php.exe folders on windows when present', function () {
    if (DIRECTORY_SEPARATOR !== '\\') {
        expect(true)->toBeTrue();

        return;
    }

    $flyenv84 = 'C:\\Program Files\\FlyEnv-Data\\app\\php-8.4.22\\php.exe';
    $flyenv85 = 'C:\\Program Files\\FlyEnv-Data\\app\\php-8.5.7\\php.exe';

    if (! is_file($flyenv84) || ! is_file($flyenv85)) {
        expect(true)->toBeTrue();

        return;
    }

    $paths = array_map(
        fn (array $row): string => strtolower(str_replace('\\', '/', (string) (realpath($row['path']) ?: $row['path']))),
        PhpRuntimeStatus::report()['binaries']
    );

    $runtime = strtolower(str_replace('\\', '/', (string) (realpath(PHP_BINARY) ?: PHP_BINARY)));
    $normalized84 = strtolower(str_replace('\\', '/', (string) realpath($flyenv84)));
    $normalized85 = strtolower(str_replace('\\', '/', (string) realpath($flyenv85)));

    // Runtime binary is excluded from "other" (realpath-deduped). Sibling FlyEnv versions must appear.
    if ($runtime !== $normalized84) {
        expect($paths)->toContain($normalized84);
    }

    if ($runtime !== $normalized85) {
        expect($paths)->toContain($normalized85);
    }
});

it('classifies php runtime versions for the php2shell widget', function () {
    expect(PhpRuntimeStatus::classifyVersion('7.4.33')['status'])->toBe('critical')
        ->and(PhpRuntimeStatus::classifyVersion('5.6.40')['status'])->toBe('critical')
        ->and(PhpRuntimeStatus::classifyVersion('8.0.30')['status'])->toBe('critical')
        ->and(PhpRuntimeStatus::classifyVersion('8.1.29')['status'])->toBe('outdated')
        // Oct 2026 critical RCE floor: 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12
        ->and(PhpRuntimeStatus::classifyVersion('8.2.34')['status'])->toBe('outdated')
        ->and(PhpRuntimeStatus::classifyVersion('8.2.35')['status'])->toBe('ok')
        ->and(PhpRuntimeStatus::classifyVersion('8.3.0')['status'])->toBe('outdated')
        ->and(PhpRuntimeStatus::classifyVersion('8.3.36')['status'])->toBe('ok')
        ->and(PhpRuntimeStatus::classifyVersion('8.4.26')['status'])->toBe('outdated')
        ->and(PhpRuntimeStatus::classifyVersion('8.4.27')['status'])->toBe('ok')
        ->and(PhpRuntimeStatus::classifyVersion('8.5.7')['status'])->toBe('outdated')
        ->and(PhpRuntimeStatus::classifyVersion('8.5.7')['minimum_secure'])->toBe('8.5.12')
        ->and(PhpRuntimeStatus::classifyVersion('8.5.11')['status'])->toBe('outdated')
        ->and(PhpRuntimeStatus::classifyVersion('8.5.12')['status'])->toBe('ok')
        ->and(PhpRuntimeStatus::classifyVersion('8.5.12')['label'])->toContain('8.5.12');
});

it('marks below-minimum php builds after the security release as suspect', function () {
    $before = PhpRuntimeStatus::classifyVersion('8.5.7', 'Oct 20 2026 12:00:00');
    $after = PhpRuntimeStatus::classifyVersion('8.5.7', 'Oct 21 2026 15:00:00');
    $secureAfter = PhpRuntimeStatus::classifyVersion('8.5.12', 'Oct 22 2026 10:00:00');
    $cutoffExact = PhpRuntimeStatus::classifyVersion('8.5.7', 'Oct 21 2026 14:00:00');
    $missingBuild = PhpRuntimeStatus::classifyVersion('8.4.22', null);

    expect($before['status'])->toBe('outdated')
        ->and($before['build_after_security_release'])->toBeFalse()
        ->and($after['status'])->toBe('suspect')
        ->and($after['build_after_security_release'])->toBeTrue()
        ->and($after['label'])->toContain('Possible security problem')
        ->and($after['build_date'])->toContain('2026-10-21')
        ->and($cutoffExact['status'])->toBe('suspect')
        ->and($secureAfter['status'])->toBe('ok')
        ->and($missingBuild['build_date'])->toBeNull()
        ->and(PhpRuntimeStatus::parseBuildDate('Oct 21 2026 14:00:00')?->format('Y-m-d H:i'))->toBe('2026-10-21 14:00');
});
