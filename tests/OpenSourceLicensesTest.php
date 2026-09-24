<?php

use EgyptDevRu\FilamentProjectPassport\Pages\OpenSourceLicensesPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerOpenSourceLicensesAuditor;
use Illuminate\Support\Facades\Cache;

it('reads open source license texts from installed packages', function () {
    $auditor = app(ComposerOpenSourceLicensesAuditor::class);
    $auditor->forgetCache();

    $result = $auditor->audit(fresh: true);
    $packages = $result['packages'];

    expect($packages)->not->toBeEmpty();

    $withText = collect($packages)->first(
        fn (array $package): bool => (bool) ($package['has_license_text'] ?? false)
            && is_string($package['license_text'] ?? null)
            && $package['license_text'] !== ''
    );

    // CI / Testbench must still resolve Composer install paths to real LICENSE files.
    expect($withText)->not->toBeNull('Expected at least one vendor package LICENSE file to be readable')
        ->and($withText['license_text'])->not->toContain("\0")
        ->and($withText['name'])->toContain('/')
        ->and($withText['license_file'])->not->toBeNull();
});

it('caches open source license results for fourteen days', function () {
    $auditor = app(ComposerOpenSourceLicensesAuditor::class);
    $auditor->forgetCache();

    $first = $auditor->audit();
    $second = $auditor->audit();

    expect($first['from_cache'])->toBeFalse()
        ->and($second['from_cache'])->toBeTrue()
        ->and($second['checked_at'])->toBe($first['checked_at'])
        ->and($second['packages'])->toBe($first['packages'])
        ->and($auditor->shouldRefresh(14))->toBeFalse();

    $refreshed = $auditor->refresh();

    expect($refreshed['from_cache'])->toBeFalse()
        ->and($refreshed['packages'])->toBeArray();
});

it('requires open source licenses refresh when cache is older than fourteen days', function () {
    $auditor = app(ComposerOpenSourceLicensesAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'packages' => [],
        'checked_at' => now()->subDays(14)->toIso8601String(),
    ], 86400);

    expect($auditor->shouldRefresh(14))->toBeTrue();
});

it('hides the open source licenses page from navigation', function () {
    expect(OpenSourceLicensesPage::shouldRegisterNavigation())->toBeFalse()
        ->and(OpenSourceLicensesPage::getNavigationItems())->toBe([]);
});

it('formats last check age for the open source licenses page', function () {
    $page = app(OpenSourceLicensesPage::class);

    $page->checkedAt = now()->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: just now');

    $page->checkedAt = now()->subDays(3)->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: 3 days ago');
});

it('filters open source packages by search', function () {
    $page = app(OpenSourceLicensesPage::class);
    $page->packages = [
        [
            'name' => 'acme/alpha',
            'version' => '1.0.0',
            'licenses' => ['MIT'],
            'license_label' => 'MIT',
            'license_text' => 'MIT text',
            'license_file' => 'LICENSE',
            'has_license_text' => true,
        ],
        [
            'name' => 'acme/beta',
            'version' => '2.0.0',
            'licenses' => ['Apache-2.0'],
            'license_label' => 'Apache-2.0',
            'license_text' => '',
            'license_file' => null,
            'has_license_text' => false,
        ],
    ];
    $page->search = 'apache';

    $filtered = $page->getFilteredPackagesProperty();

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['name'])->toBe('acme/beta')
        ->and($page->packagesWithLicenseTextCount())->toBe(1);
});
