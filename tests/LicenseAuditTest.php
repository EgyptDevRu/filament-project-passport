<?php

use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use Illuminate\Support\Facades\Cache;

it('classifies commercial-friendly licenses as compatible', function () {
    expect(LicenseStatus::classify(['MIT']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['BSD-3-Clause']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['Apache-2.0']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['ISC']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['Unlicense']))->toBe(LicenseStatus::COMPATIBLE);
});

it('marks dual-licensed packages compatible when one license is commercial-friendly', function () {
    expect(LicenseStatus::classify(['BSD-3-Clause', 'GPL-3.0']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['GPL-3.0-only', 'MIT']))->toBe(LicenseStatus::COMPATIBLE);
});

it('marks copyleft-only packages as incompatible', function () {
    expect(LicenseStatus::classify(['GPL-3.0']))->toBe(LicenseStatus::INCOMPATIBLE)
        ->and(LicenseStatus::classify(['AGPL-3.0-or-later']))->toBe(LicenseStatus::INCOMPATIBLE)
        ->and(LicenseStatus::classify(['GPL-2.0', 'GPL-3.0']))->toBe(LicenseStatus::INCOMPATIBLE)
        ->and(LicenseStatus::classify(['SSPL-1.0']))->toBe(LicenseStatus::INCOMPATIBLE);
});

it('marks review and unknown licenses as requires review', function () {
    expect(LicenseStatus::classify(['MPL-2.0']))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify(['LGPL-3.0']))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify([]))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify(['Custom-License']))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify(['GPL-3.0', 'MPL-2.0']))->toBe(LicenseStatus::REQUIRES_REVIEW);
});

it('reads licenses from installed.json for all installed packages', function () {
    $auditor = app(ComposerLicenseAuditor::class);
    $auditor->forgetCache();

    $result = $auditor->audit(fresh: true);
    $packages = $result['packages'];

    expect($packages)->not->toBeEmpty();

    $withKnownLicense = collect($packages)->first(
        fn (array $package): bool => ($package['license_label'] ?? 'Unknown') !== 'Unknown'
    );

    expect($withKnownLicense)->not->toBeNull()
        ->and($withKnownLicense['licenses'])->not->toBeEmpty()
        ->and($withKnownLicense['status'])->not->toBeEmpty();

    // Transitive packages should be included (not only root require entries).
    $names = collect($packages)->pluck('name');
    expect($names->contains(fn (string $name): bool => str_contains($name, '/')))->toBeTrue();
});

it('detects incompatible packages in an audit result set', function () {
    $auditor = app(ComposerLicenseAuditor::class);

    $packages = [
        [
            'name' => 'foo/bar',
            'version' => '1.0.0',
            'licenses' => ['MIT'],
            'license_label' => 'MIT',
            'status' => LicenseStatus::COMPATIBLE,
            'status_label' => 'Compatible',
        ],
        [
            'name' => 'copy/left',
            'version' => '2.0.0',
            'licenses' => ['GPL-3.0'],
            'license_label' => 'GPL-3.0',
            'status' => LicenseStatus::INCOMPATIBLE,
            'status_label' => 'Incompatible',
        ],
    ];

    expect($auditor->hasIncompatible($packages))->toBeTrue()
        ->and($auditor->incompatiblePackages($packages))->toHaveCount(1)
        ->and($auditor->incompatiblePackages($packages)[0]['name'])->toBe('copy/left');
});

it('caches license audit results for fourteen days', function () {
    $auditor = app(ComposerLicenseAuditor::class);
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

it('requires license audit refresh when cache is older than fourteen days', function () {
    $auditor = app(ComposerLicenseAuditor::class);
    $auditor->forgetCache();

    Cache::put($auditor->cacheKey(), [
        'packages' => [],
        'checked_at' => now()->subDays(14)->toIso8601String(),
    ], 86400);

    expect($auditor->shouldRefresh(14))->toBeTrue();
});

it('formats last check age for the license audit page', function () {
    $page = app(LicenseAuditPage::class);

    $page->checkedAt = now()->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: just now');

    $page->checkedAt = now()->subDays(3)->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: 3 days ago');

    $page->checkedAt = now()->subDay()->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: 1 day ago');

    $page->checkedAt = now()->subHours(5)->toIso8601String();
    expect($page->lastCheckSummary())->toBe('Last check: 5 hours ago');
});

it('labels check ages consistently', function () {
    expect(CheckAge::label(null))->toBe('unknown')
        ->and(CheckAge::label(now()->subMinutes(12)->toIso8601String()))
        ->toBe('12 minutes ago');
});
