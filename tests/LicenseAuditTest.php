<?php

use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\CheckAge;
use EgyptDevRu\FilamentProjectPassport\Support\ComposerPackageReplaceMap;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

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
    expect(LicenseStatus::classify(['LGPL-3.0']))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify([]))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify(['Custom-License']))->toBe(LicenseStatus::REQUIRES_REVIEW)
        ->and(LicenseStatus::classify(['GPL-3.0', 'LGPL-3.0']))->toBe(LicenseStatus::REQUIRES_REVIEW);
});

it('treats mpl and cc-by as compatible when listed for attribution', function () {
    expect(LicenseStatus::classify(['MPL-2.0']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['CC-BY-4.0']))->toBe(LicenseStatus::COMPATIBLE);
});

it('strips spdx only and or-later suffixes for gpl family licenses', function () {
    foreach ([
        'GPL-2.0-only',
        'GPL-2.0-or-later',
        'GPL-3.0-only',
        'GPL-3.0-or-later',
        'AGPL-3.0-only',
        'AGPL-3.0-or-later',
    ] as $license) {
        expect(LicenseStatus::classify([$license]))->toBe(LicenseStatus::INCOMPATIBLE, $license);
    }
});

it('treats spdx OR expressions and composer arrays as dual-license choice', function () {
    expect(LicenseStatus::classify(['MIT OR GPL-3.0']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['(MIT OR Apache-2.0)']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['GPL-2.0 OR GPL-3.0']))->toBe(LicenseStatus::INCOMPATIBLE);
});

it('treats spdx AND expressions as requiring every license', function () {
    expect(LicenseStatus::classify(['MIT AND Apache-2.0']))->toBe(LicenseStatus::COMPATIBLE)
        ->and(LicenseStatus::classify(['MIT AND GPL-3.0']))->toBe(LicenseStatus::INCOMPATIBLE)
        ->and(LicenseStatus::classify(['MIT AND LGPL-3.0']))->toBe(LicenseStatus::REQUIRES_REVIEW);
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

    passportPutSignedCache($auditor->cacheKey(), [
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

it('inherits licenses from composer replace monorepo parents', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fpp-replace-installed-'.uniqid('', true).'.json';

    File::put($path, json_encode([
        'packages' => [
            [
                'name' => 'acme/monorepo',
                'version' => '1.2.3',
                'license' => ['MIT'],
                'replace' => [
                    'acme/stub' => 'self.version',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $records = ComposerPackageReplaceMap::inheritMissing([
            [
                'name' => 'acme/monorepo',
                'version' => '1.2.3',
                'licenses' => ['MIT'],
                'install_path' => '/tmp/acme-monorepo',
            ],
            [
                'name' => 'acme/stub',
                'version' => '—',
                'licenses' => [],
                'install_path' => null,
            ],
        ], $path);

        expect($records[1]['licenses'])->toBe(['MIT'])
            ->and($records[1]['install_path'])->toBe('/tmp/acme-monorepo')
            ->and($records[1]['version'])->toBe('1.2.3')
            ->and(ComposerPackageReplaceMap::replacedBy($path))->toBe(['acme/stub' => 'acme/monorepo']);
    } finally {
        File::delete($path);
    }
});

it('inherits licenses from composer provide virtual packages', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fpp-provide-installed-'.uniqid('', true).'.json';

    File::put($path, json_encode([
        'packages' => [
            [
                'name' => 'monolog/monolog',
                'version' => '3.0.0',
                'license' => ['MIT'],
                'provide' => [
                    'psr/log-implementation' => '3.0.0',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $records = ComposerPackageReplaceMap::inheritMissing([
            [
                'name' => 'monolog/monolog',
                'version' => '3.0.0',
                'licenses' => ['MIT'],
                'install_path' => '/tmp/monolog',
            ],
            [
                'name' => 'psr/log-implementation',
                'version' => '—',
                'licenses' => [],
                'install_path' => null,
            ],
        ], $path);

        expect($records[1]['licenses'])->toBe(['MIT'])
            ->and($records[1]['install_path'])->toBe('/tmp/monolog')
            ->and($records[1]['version'])->toBe('3.0.0')
            ->and(ComposerPackageReplaceMap::parentBy($path)['psr/log-implementation'])->toBe('monolog/monolog');
    } finally {
        File::delete($path);
    }
});

it('shows non-unknown licenses for provided psr implementation stubs when present', function () {
    $map = ComposerPackageReplaceMap::parentBy();

    if ($map === [] || ! isset($map['psr/log-implementation'])) {
        test()->markTestSkipped('No package provides psr/log-implementation in this vendor tree.');
    }

    $auditor = app(ComposerLicenseAuditor::class);
    $auditor->forgetCache();
    $packages = collect($auditor->audit(fresh: true)['packages']);
    $stub = $packages->firstWhere('name', 'psr/log-implementation');

    expect($stub)->not->toBeNull()
        ->and($stub['license_label'])->not->toBe('Unknown')
        ->and($stub['licenses'])->not->toBeEmpty();
});

it('shows non-unknown licenses for replaced illuminate packages when laravel/framework is present', function () {
    $map = ComposerPackageReplaceMap::replacedBy();

    if ($map === [] || ! isset($map['illuminate/support'])) {
        test()->markTestSkipped('laravel/framework replace map for illuminate/support is not present in this vendor tree.');
    }

    $auditor = app(ComposerLicenseAuditor::class);
    $auditor->forgetCache();
    $packages = collect($auditor->audit(fresh: true)['packages']);
    $support = $packages->firstWhere('name', 'illuminate/support');

    expect($support)->not->toBeNull()
        ->and($support['license_label'])->not->toBe('Unknown')
        ->and($support['licenses'])->not->toBeEmpty();
});
