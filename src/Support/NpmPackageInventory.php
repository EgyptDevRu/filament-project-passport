<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Discovers host-app NPM packages from package-lock.json (preferred) or package.json.
 *
 * @phpstan-type NpmPackageRecord array{
 *     name: string,
 *     version: string,
 *     licenses: list<string>,
 *     install_path: string|null
 * }
 */
final class NpmPackageInventory
{
    /**
     * Fingerprint source for cache keys (lockfile preferred).
     */
    public static function fingerprintPath(): ?string
    {
        $lock = base_path('package-lock.json');

        if (File::isFile($lock)) {
            return $lock;
        }

        $packageJson = base_path('package.json');

        return File::isFile($packageJson) ? $packageJson : null;
    }

    public static function fingerprint(): string
    {
        $path = self::fingerprintPath();

        if ($path === null) {
            return md5('npm:missing:'.base_path());
        }

        try {
            $hash = md5_file($path);
        } catch (Throwable) {
            return md5($path);
        }

        return is_string($hash) ? $hash : md5($path);
    }

    /**
     * True when the host app ships package.json and/or package-lock.json.
     * Blade/Alpine apps without an NPM front-end return false even if Node is installed.
     */
    public static function hasManifest(): bool
    {
        return self::fingerprintPath() !== null;
    }

    /**
     * @return list<NpmPackageRecord>
     */
    public static function records(): array
    {
        try {
            $fromLock = self::recordsFromPackageLock();

            if ($fromLock !== []) {
                return $fromLock;
            }

            return self::recordsFromPackageJson();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<NpmPackageRecord>
     */
    private static function recordsFromPackageLock(): array
    {
        $path = base_path('package-lock.json');

        if (! File::isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $packages = $json['packages'] ?? null;

        if (! is_array($packages)) {
            return [];
        }

        $rootName = self::rootPackageName();
        $records = [];

        foreach ($packages as $key => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = (string) $key;

            // Root package entry in lockfile v2/v3.
            if ($key === '') {
                continue;
            }

            if (! str_starts_with($key, 'node_modules/')) {
                continue;
            }

            // Nested dependency paths: use the leaf package name.
            $name = self::nameFromNodeModulesPath($key);

            if ($name === null || ($rootName !== null && $name === $rootName)) {
                continue;
            }

            $isTopLevel = self::isTopLevelLockKey($key);

            // Prefer a top-level node_modules copy over a nested duplicate.
            if (isset($records[$name]) && ! $isTopLevel) {
                continue;
            }

            $version = isset($meta['version']) && is_string($meta['version']) && $meta['version'] !== ''
                ? $meta['version']
                : '—';

            $installPath = self::resolveInstallPath($key);
            $licenses = self::normalizeLicenseList($meta['license'] ?? null);

            if ($licenses === [] && $installPath !== null) {
                $licenses = self::licensesFromPackageJsonFile(
                    $installPath.DIRECTORY_SEPARATOR.'package.json'
                );
            }

            $records[$name] = [
                'name' => $name,
                'version' => $version,
                'licenses' => $licenses,
                'install_path' => $installPath,
            ];
        }

        $list = array_values($records);

        usort(
            $list,
            fn (array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $list;
    }

    /**
     * @return list<NpmPackageRecord>
     */
    private static function recordsFromPackageJson(): array
    {
        $path = base_path('package.json');

        if (! File::isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $rootName = isset($json['name']) && is_string($json['name']) && $json['name'] !== ''
            ? $json['name']
            : null;

        $names = [];

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            $deps = $json[$section] ?? null;

            if (! is_array($deps)) {
                continue;
            }

            foreach (array_keys($deps) as $name) {
                $name = trim((string) $name);

                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        $records = [];

        foreach (array_keys($names) as $name) {
            if ($rootName !== null && $name === $rootName) {
                continue;
            }

            $installPath = self::resolveInstallPath('node_modules/'.$name);
            $version = '—';
            $licenses = [];

            if ($installPath !== null) {
                $packageJson = $installPath.DIRECTORY_SEPARATOR.'package.json';
                $meta = self::readJsonObject($packageJson);

                if ($meta !== null) {
                    if (isset($meta['version']) && is_string($meta['version']) && $meta['version'] !== '') {
                        $version = $meta['version'];
                    }

                    $licenses = self::normalizeLicenseList($meta['license'] ?? null);
                }
            }

            $records[] = [
                'name' => $name,
                'version' => $version,
                'licenses' => $licenses,
                'install_path' => $installPath,
            ];
        }

        usort(
            $records,
            fn (array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $records;
    }

    private static function rootPackageName(): ?string
    {
        $path = base_path('package.json');

        if (! File::isFile($path)) {
            return null;
        }

        $json = self::readJsonObject($path);

        if ($json === null) {
            return null;
        }

        $name = $json['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private static function nameFromNodeModulesPath(string $lockKey): ?string
    {
        // node_modules/lodash → lodash
        // node_modules/@scope/pkg → @scope/pkg
        // node_modules/a/node_modules/b → b
        $parts = explode('/node_modules/', $lockKey);
        $leaf = $parts[array_key_last($parts)] ?? '';

        if (str_starts_with($leaf, 'node_modules/')) {
            $leaf = substr($leaf, strlen('node_modules/'));
        }

        $leaf = trim($leaf, '/');

        return $leaf !== '' ? $leaf : null;
    }

    private static function isTopLevelLockKey(string $lockKey): bool
    {
        if (! str_starts_with($lockKey, 'node_modules/')) {
            return false;
        }

        $rest = substr($lockKey, strlen('node_modules/'));

        return $rest !== '' && ! str_contains($rest, 'node_modules/');
    }

    private static function resolveInstallPath(string $relativeNodeModulesPath): ?string
    {
        $absolute = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeNodeModulesPath));
        $resolved = realpath($absolute);

        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    /**
     * @return list<string>
     */
    private static function licensesFromPackageJsonFile(string $path): array
    {
        $json = self::readJsonObject($path);

        if ($json === null) {
            return [];
        }

        return self::normalizeLicenseList($json['license'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJsonObject(string $path): ?array
    {
        if (! File::isFile($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return $json;
    }

    /**
     * @return list<string>
     */
    public static function normalizeLicenseList(mixed $license): array
    {
        if (is_string($license)) {
            $license = trim($license);

            return $license === '' ? [] : [$license];
        }

        if (is_array($license)) {
            // package.json SPDX object: { "type": "MIT", "url": "..." }
            if (isset($license['type']) && is_string($license['type'])) {
                $type = trim($license['type']);

                return $type === '' ? [] : [$type];
            }

            return array_values(array_filter(array_map(
                static function (mixed $item): string {
                    if (is_array($item) && isset($item['type']) && is_string($item['type'])) {
                        return trim($item['type']);
                    }

                    return trim((string) $item);
                },
                $license
            )));
        }

        return [];
    }
}
