<?php

use EgyptDevRu\FilamentProjectPassport\FilamentProjectPassportServiceProvider;
use EgyptDevRu\FilamentProjectPassport\Pages\SecurityAuditPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Services\NpmDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\NpmRuntime;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use EgyptDevRu\FilamentProjectPassport\Support\PhpRuntimeStatus;
use EgyptDevRu\FilamentProjectPassport\Support\ShieldIntegration;
use EgyptDevRu\FilamentProjectPassport\Support\SignedCachePayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    NpmRuntime::fake(null);
    Cache::forget('filament-project-passport.security-audit.manual-refresh');
    PhpRuntimeStatus::forgetCache();
});

it('uses the same page authorizer as other developer support pages', function () {
    expect(SecurityAuditPage::canAccess())->toBe(PageAuthorizer::canAccess())
        ->and(SecurityAuditPage::getNavigationLabel())->toBe('Security Audit')
        ->and(config('filament-project-passport.navigation.pages.security_audit.label'))->toBe('Security Audit')
        ->and(config('filament-project-passport.navigation.pages.security_audit.sort'))->toBe(4);
});

it('hydrates security audit counts from dependency cache without scanning', function () {
    NpmRuntime::fake(true);

    $composer = app(ComposerDependencyAuditor::class);
    $npm = app(NpmDependencyAuditor::class);
    $composer->forgetCache();
    $npm->forgetCache();

    passportPutSignedCache($composer->cacheKey(), [
        'outdated_count' => 3,
        'advisory_count' => 2,
        'high_advisory_count' => 1,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    passportPutSignedCache($npm->cacheKey(), [
        'outdated_count' => 4,
        'advisory_count' => 1,
        'high_advisory_count' => 1,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
        'available' => true,
        'runnable' => true,
        'node_available' => true,
    ], 86400);

    $page = app(SecurityAuditPage::class);
    $page->mount();

    expect($page->outdatedCount())->toBe(3)
        ->and($page->advisoryCount())->toBe(2)
        ->and($page->highAdvisoryCount())->toBe(1)
        ->and($page->npmOutdatedCount())->toBe(4)
        ->and($page->npmAdvisoryCount())->toBe(1)
        ->and($page->npmHighAdvisoryCount())->toBe(1)
        ->and($page->dependencyAuditUrl())->toContain('dependency-audit');
});

it('exposes php2shell runtime status on the security audit page', function () {
    PhpRuntimeStatus::forgetCache();

    $page = app(SecurityAuditPage::class);
    $status = $page->phpRuntimeStatus();

    expect($status)->toHaveKeys(['runtime', 'binaries', 'is_secure', 'has_critical', 'has_suspect', 'checked_at', 'from_cache', 'cloudflare_note'])
        ->and($status['runtime']['version'])->toBe(PHP_VERSION)
        ->and($status['runtime'])->toHaveKeys(['build_date', 'build_after_security_release', 'minimum_secure'])
        ->and($status['from_cache'])->toBeFalse()
        ->and($status['checked_at'])->not->toBeNull()
        ->and($status['cloudflare_note'])->toContain('Cloudflare');

    $cached = $page->phpRuntimeStatus();

    expect($cached['from_cache'])->toBeTrue()
        ->and($cached['checked_at'])->toBe($status['checked_at']);
});

it('rescans php2shell when security audit refresh runs', function () {
    PhpRuntimeStatus::forgetCache();
    PhpRuntimeStatus::report();
    expect(PhpRuntimeStatus::report()['from_cache'])->toBeTrue();

    $composer = app(ComposerDependencyAuditor::class);
    $composer->forgetCache();
    $composer->clearRefreshRunning();

    $page = app(SecurityAuditPage::class);
    $page->mount();

    expect($page->canManualRefresh())->toBeTrue();

    $page->refreshSecurityAudit();

    expect($page->canManualRefresh())->toBeFalse()
        ->and(PhpRuntimeStatus::shouldRefresh())->toBeFalse()
        ->and(PhpRuntimeStatus::report()['from_cache'])->toBeTrue()
        ->and(PhpRuntimeStatus::report()['checked_at'])->not->toBeNull();
});

it('registers security audit in the package page list and shield exclusions', function () {
    expect(FilamentProjectPassportServiceProvider::PAGES)
        ->toContain(SecurityAuditPage::class)
        ->and(ShieldIntegration::PAGE_CLASSES)
        ->toContain(SecurityAuditPage::class);
});

it('allows manual security audit refresh once per day including after failure', function () {
    $composer = app(ComposerDependencyAuditor::class);
    $composer->forgetCache();
    $composer->clearRefreshRunning();

    $page = app(SecurityAuditPage::class);
    $page->mount();

    expect($page->canManualRefresh())->toBeTrue()
        ->and($page->refreshButtonLabel())->toBe('Refresh');

    $page->refreshSecurityAudit();

    expect($page->canManualRefresh())->toBeFalse()
        ->and($page->refreshing)->toBeTrue()
        ->and($composer->isRefreshRunning())->toBeTrue()
        ->and($page->refreshButtonLabel())->toBe('Refreshing…')
        ->and($page->manualRefreshHint())->toContain('in progress');

    // Simulate a failed job finishing — daily slot stays consumed.
    $composer->rememberFailure('Dependency audit job failed or timed out.');
    $page->pollPageData();

    expect($page->refreshing)->toBeFalse()
        ->and($page->canManualRefresh())->toBeFalse()
        ->and($page->refreshButtonLabel())->toBe('Refresh used today')
        ->and($page->manualRefreshHint())->toContain('Refresh used today');

    $page->refreshSecurityAudit();

    expect($page->canManualRefresh())->toBeFalse()
        ->and($page->refreshing)->toBeFalse();
});

it('rejects tampered security audit manual-refresh cache entries', function () {
    $page = app(SecurityAuditPage::class);
    $page->mount();

    expect($page->canManualRefresh())->toBeTrue();

    Cache::put('filament-project-passport.security-audit.manual-refresh', [
        'date' => now()->toDateString(),
        'used_at' => now()->toIso8601String(),
    ], now()->endOfDay());

    expect($page->canManualRefresh())->toBeFalse()
        ->and($page->refreshButtonLabel())->toBe('Refresh used today');

    Cache::put(
        'filament-project-passport.security-audit.manual-refresh',
        SignedCachePayload::seal([
            'date' => now()->toDateString(),
            'used_at' => now()->toIso8601String(),
        ]),
        now()->endOfDay()
    );

    $forged = Cache::get('filament-project-passport.security-audit.manual-refresh');
    expect($forged)->toBeArray();
    $forged['date'] = now()->subDay()->toDateString();
    Cache::put('filament-project-passport.security-audit.manual-refresh', $forged, now()->endOfDay());

    expect($page->canManualRefresh())->toBeFalse();
});

it('rejects tampered dependency audit checked_at values', function () {
    $auditor = app(ComposerDependencyAuditor::class);
    $auditor->forgetCache();

    passportPutSignedCache($auditor->cacheKey(), [
        'outdated_count' => 0,
        'advisory_count' => 0,
        'outdated' => [],
        'advisories' => [],
        'checked_at' => now()->toIso8601String(),
        'error' => null,
    ], 86400);

    expect($auditor->cachedPayload())->toBeArray()
        ->and($auditor->shouldRefresh())->toBeFalse();

    $raw = Cache::get($auditor->cacheKey());
    expect($raw)->toBeArray();
    $raw['checked_at'] = now()->subDays(30)->toIso8601String();
    Cache::put($auditor->cacheKey(), $raw, 86400);

    expect($auditor->cachedPayload())->toBeNull()
        ->and($auditor->completedPayload())->toBeNull()
        ->and($auditor->shouldRefresh())->toBeTrue();
});

it('rejects tampered license api cache payloads', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => true,
            'project_name' => 'Example Project',
        ], 200),
    ]);

    config()->set('app.url', 'https://app.test');

    $client = app(LicenseApiClient::class);
    $client->forgetCache();
    $client->fetch();

    expect($client->cachedPayload())->toBeArray()
        ->and($client->cachedPayload()['is_official'])->toBeTrue()
        ->and($client->cachedPayload())->toHaveKey('cached_at');

    $key = (new ReflectionClass($client))->getMethod('cacheKey');
    $key->setAccessible(true);
    $cacheKey = $key->invoke($client);

    $raw = Cache::get($cacheKey);
    expect($raw)->toBeArray()
        ->and($raw)->toHaveKey('_passport_sig')
        ->and($raw)->toHaveKey('cached_at');

    $raw['is_official'] = false;
    $raw['cached_at'] = now()->subDays(30)->toIso8601String();
    Cache::put($cacheKey, $raw, 86400);

    expect($client->cachedPayload())->toBeNull()
        ->and($client->isKnownUnofficialFromCache())->toBeFalse();
});
