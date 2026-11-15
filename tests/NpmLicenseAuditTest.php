<?php

use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\OpenSourceLicensesPage;
use EgyptDevRu\FilamentProjectPassport\Services\NpmLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\NpmOpenSourceLicensesAuditor;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseStatus;
use EgyptDevRu\FilamentProjectPassport\Support\NpmPackageInventory;
use Illuminate\Support\Facades\File;

function npmTestCleanup(): void
{
    foreach ([
        base_path('package-lock.json'),
        base_path('package.json'),
    ] as $path) {
        if (File::isFile($path)) {
            File::delete($path);
        }
    }

    $modules = base_path('node_modules');

    if (File::isDirectory($modules)) {
        File::deleteDirectory($modules);
    }
}

function npmTestSeedLockfileFixture(): void
{
    npmTestCleanup();

    File::put(base_path('package.json'), json_encode([
        'name' => 'host-app',
        'private' => true,
        'dependencies' => [
            'left-pad' => '1.3.0',
            'copyleft-demo' => '1.0.0',
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
                    'copyleft-demo' => '1.0.0',
                ],
            ],
            'node_modules/left-pad' => [
                'version' => '1.3.0',
                'license' => 'MIT',
            ],
            'node_modules/copyleft-demo' => [
                'version' => '1.0.0',
                'license' => 'GPL-3.0',
            ],
            'node_modules/left-pad/node_modules/nested-only' => [
                'version' => '9.9.9',
                'license' => 'ISC',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    $leftPad = base_path('node_modules/left-pad');
    File::ensureDirectoryExists($leftPad);
    File::put($leftPad.'/package.json', json_encode([
        'name' => 'left-pad',
        'version' => '1.3.0',
        'license' => 'MIT',
    ], JSON_THROW_ON_ERROR));
    File::put($leftPad.'/LICENSE', "MIT License\n\nCopyright (c) left-pad authors");

    $copyleft = base_path('node_modules/copyleft-demo');
    File::ensureDirectoryExists($copyleft);
    File::put($copyleft.'/package.json', json_encode([
        'name' => 'copyleft-demo',
        'version' => '1.0.0',
        'license' => 'GPL-3.0',
    ], JSON_THROW_ON_ERROR));
}

afterEach(function () {
    // Only clean when this file created fixtures (paths may not exist otherwise).
    if (File::isFile(base_path('package-lock.json')) || File::isDirectory(base_path('node_modules'))) {
        // Avoid wiping unrelated trees: only remove if our host-app fixture marker exists.
        $packageJson = base_path('package.json');

        if (File::isFile($packageJson)) {
            $json = json_decode((string) File::get($packageJson), true);

            if (is_array($json) && ($json['name'] ?? null) === 'host-app') {
                npmTestCleanup();
            }
        }
    }
});

it('returns an empty npm audit when no package.json exists', function () {
    npmTestCleanup();

    $auditor = app(NpmLicenseAuditor::class);
    $auditor->forgetCache();

    $result = $auditor->audit(fresh: true);

    expect($result['packages'])->toBe([])
        ->and($result['from_cache'])->toBeFalse()
        ->and(NpmPackageInventory::fingerprintPath())->toBeNull()
        ->and(NpmPackageInventory::hasManifest())->toBeFalse()
        ->and(NpmPackageInventory::records())->toBe([]);

    // After the empty scan is cached, do not keep re-scheduling work for Blade/Alpine hosts.
    expect($auditor->shouldRefresh(14))->toBeFalse();

    $oss = app(NpmOpenSourceLicensesAuditor::class);
    $oss->forgetCache();
    $ossResult = $oss->audit(fresh: true);

    expect($ossResult['packages'])->toBe([])
        ->and($oss->shouldRefresh(14))->toBeFalse();
});

it('reads npm licenses from package-lock.json', function () {
    npmTestSeedLockfileFixture();

    $auditor = app(NpmLicenseAuditor::class);
    $auditor->forgetCache();

    $result = $auditor->audit(fresh: true);
    $byName = collect($result['packages'])->keyBy('name');

    expect($byName)->toHaveKeys(['left-pad', 'copyleft-demo', 'nested-only'])
        ->and($byName['left-pad']['license_label'])->toBe('MIT')
        ->and($byName['left-pad']['status'])->toBe(LicenseStatus::COMPATIBLE)
        ->and($byName['copyleft-demo']['status'])->toBe(LicenseStatus::INCOMPATIBLE)
        ->and($byName['nested-only']['license_label'])->toBe('ISC');
});

it('caches npm license audit results for fourteen days', function () {
    npmTestSeedLockfileFixture();

    $auditor = app(NpmLicenseAuditor::class);
    $auditor->forgetCache();

    $first = $auditor->audit();
    $second = $auditor->audit();

    expect($first['from_cache'])->toBeFalse()
        ->and($second['from_cache'])->toBeTrue()
        ->and($second['packages'])->toBe($first['packages'])
        ->and($auditor->shouldRefresh(14))->toBeFalse();

    passportPutSignedCache($auditor->cacheKey(), [
        'packages' => [],
        'checked_at' => now()->subDays(14)->toIso8601String(),
    ], 86400);

    expect($auditor->shouldRefresh(14))->toBeTrue();
});

it('reads npm license text from node_modules LICENSE files', function () {
    npmTestSeedLockfileFixture();

    $auditor = app(NpmOpenSourceLicensesAuditor::class);
    $auditor->forgetCache();

    $result = $auditor->audit(fresh: true);
    $leftPad = collect($result['packages'])->firstWhere('name', 'left-pad');

    expect($leftPad)->not->toBeNull()
        ->and($leftPad['has_license_text'])->toBeTrue()
        ->and($leftPad['license_file'])->toBe('LICENSE')
        ->and($leftPad['license_text'])->toContain('MIT License')
        ->and($leftPad['license_label'])->toBe('MIT');
});

it('filters npm packages on the license audit page', function () {
    $page = app(LicenseAuditPage::class);
    $page->npmPackages = [
        [
            'name' => 'left-pad',
            'version' => '1.3.0',
            'licenses' => ['MIT'],
            'license_label' => 'MIT',
            'status' => LicenseStatus::COMPATIBLE,
            'status_label' => 'Compatible',
        ],
        [
            'name' => 'copyleft-demo',
            'version' => '1.0.0',
            'licenses' => ['GPL-3.0'],
            'license_label' => 'GPL-3.0',
            'status' => LicenseStatus::INCOMPATIBLE,
            'status_label' => 'Incompatible',
        ],
    ];
    $page->npmSearch = 'gpl';

    expect($page->getFilteredNpmPackagesProperty())->toHaveCount(1)
        ->and($page->getFilteredNpmPackagesProperty()[0]['name'])->toBe('copyleft-demo')
        ->and($page->hasIncompatiblePackages())->toBeTrue();
});

it('filters npm packages on the open source licenses page', function () {
    $page = app(OpenSourceLicensesPage::class);
    $page->npmPackages = [
        [
            'name' => 'left-pad',
            'version' => '1.3.0',
            'licenses' => ['MIT'],
            'license_label' => 'MIT',
            'license_text' => 'MIT text',
            'license_file' => 'LICENSE',
            'has_license_text' => true,
        ],
        [
            'name' => 'copyleft-demo',
            'version' => '1.0.0',
            'licenses' => ['GPL-3.0'],
            'license_label' => 'GPL-3.0',
            'license_text' => '',
            'license_file' => null,
            'has_license_text' => false,
        ],
    ];
    $page->npmSearch = 'left-pad';

    expect($page->getFilteredNpmPackagesProperty())->toHaveCount(1)
        ->and($page->getFilteredNpmPackagesProperty()[0]['name'])->toBe('left-pad')
        ->and($page->npmPackagesWithLicenseTextCount())->toBe(1);
});
