<?php

use EgyptDevRu\FilamentProjectPassport\Support\ComposerBinary;
use Illuminate\Support\Facades\File;

it('prefers project composer.phar when present', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fi-pp-composer-'.uniqid();
    File::makeDirectory($dir);
    File::put($dir.DIRECTORY_SEPARATOR.'composer.phar', "<?php\necho 'Composer PHAR stub';\n");

    try {
        $candidates = ComposerBinary::candidates($dir);

        expect($candidates[0][0])->toBe(ComposerBinary::phpCliBinary())
            ->and($candidates[0][1])->toEndWith('composer.phar')
            ->and($candidates)->toContain(['composer']);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('resolves a php cli binary even when PHP_BINARY looks like cgi', function () {
    expect(ComposerBinary::phpCliBinary())->not->toBe('')
        ->and(strtolower(ComposerBinary::phpCliBinary()))->not->toContain('php-cgi')
        ->and(strtolower(ComposerBinary::phpCliBinary()))->not->toContain('php-fpm');
});

it('falls back to global composer when phar is missing', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fi-pp-composer-'.uniqid();
    File::makeDirectory($dir);

    try {
        $candidates = ComposerBinary::candidates($dir);

        expect($candidates[0])->toBe(['composer']);
    } finally {
        File::deleteDirectory($dir);
    }
});
