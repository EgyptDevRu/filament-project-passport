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

it('sets a writable COMPOSER_HOME when the host environment has neither HOME nor COMPOSER_HOME', function () {
    $originalHome = getenv('HOME');
    $originalComposerHome = getenv('COMPOSER_HOME');
    $originalEnvHome = $_ENV['HOME'] ?? null;
    $originalEnvComposerHome = $_ENV['COMPOSER_HOME'] ?? null;

    putenv('HOME');
    putenv('COMPOSER_HOME');
    unset($_ENV['HOME'], $_ENV['COMPOSER_HOME']);

    try {
        $env = ComposerBinary::inheritedEnvironment();

        expect($env)->not->toHaveKey('HOME')
            ->and($env['COMPOSER_HOME'] ?? '')->not->toBe('')
            ->and(is_dir($env['COMPOSER_HOME']))->toBeTrue();
    } finally {
        $originalHome === false ? putenv('HOME') : putenv("HOME={$originalHome}");
        $originalComposerHome === false ? putenv('COMPOSER_HOME') : putenv("COMPOSER_HOME={$originalComposerHome}");

        if ($originalEnvHome !== null) {
            $_ENV['HOME'] = $originalEnvHome;
        }

        if ($originalEnvComposerHome !== null) {
            $_ENV['COMPOSER_HOME'] = $originalEnvComposerHome;
        }
    }
});

it('leaves an existing HOME or COMPOSER_HOME untouched', function () {
    $originalComposerHome = getenv('COMPOSER_HOME');
    putenv('COMPOSER_HOME=/tmp/fi-pp-existing-composer-home');
    $_ENV['COMPOSER_HOME'] = '/tmp/fi-pp-existing-composer-home';

    try {
        $env = ComposerBinary::inheritedEnvironment();

        expect($env['COMPOSER_HOME'])->toBe('/tmp/fi-pp-existing-composer-home');
    } finally {
        $originalComposerHome === false ? putenv('COMPOSER_HOME') : putenv("COMPOSER_HOME={$originalComposerHome}");

        if ($originalComposerHome === false) {
            unset($_ENV['COMPOSER_HOME']);
        } else {
            $_ENV['COMPOSER_HOME'] = $originalComposerHome;
        }
    }
});
