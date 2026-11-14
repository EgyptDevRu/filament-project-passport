<?php

use EgyptDevRu\FilamentProjectPassport\Services\ThirdPartyLicensesWriter;
use Illuminate\Support\Facades\File;

it('writes THIRD-PARTY-LICENSES.txt to the host application root', function () {
    $path = base_path(ThirdPartyLicensesWriter::FILENAME);

    if (File::isFile($path)) {
        File::delete($path);
    }

    $result = app(ThirdPartyLicensesWriter::class)->write([
        [
            'name' => 'acme/alpha',
            'version' => '1.0.0',
            'license_label' => 'MIT',
            'license_text' => "The MIT License (MIT)\n\nCopyright (c) Acme",
        ],
    ], [
        [
            'name' => 'left-pad',
            'version' => '1.3.0',
            'license_label' => 'MIT',
            'license_text' => '',
        ],
    ]);

    expect($result['path'])->toBe($path)
        ->and($result['packages'])->toBe(2)
        ->and(File::isFile($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('Package: acme/alpha')
        ->and($contents)->toContain('License: MIT')
        ->and($contents)->toContain('The MIT License (MIT)')
        ->and($contents)->toContain('Package: left-pad')
        ->and($contents)->toContain('Ecosystem: NPM')
        ->and($contents)->toContain(str_repeat('=', 80));

    File::delete($path);
});
