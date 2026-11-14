<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Resolves Composer "replace" monorepo stubs and "provide" virtual packages
 * (e.g. illuminate/* → laravel/framework, psr/log-implementation → monolog/monolog)
 * so license audits can inherit the real package's license metadata.
 */
final class ComposerPackageReplaceMap
{
    /**
     * Map of virtual/stub package name → concrete package name (replace first, then provide).
     *
     * @return array<string, string>
     */
    public static function parentBy(?string $installedJsonPath = null): array
    {
        $path = $installedJsonPath ?? base_path('vendor/composer/installed.json');
        $packages = self::packagesFromInstalledJson($path);

        if ($packages === []) {
            return [];
        }

        $map = [];

        foreach ($packages as $package) {
            $parent = isset($package['name']) ? (string) $package['name'] : '';

            if ($parent === '') {
                continue;
            }

            foreach (['replace', 'provide'] as $key) {
                $entries = $package[$key] ?? null;

                if (! is_array($entries)) {
                    continue;
                }

                foreach (array_keys($entries) as $virtual) {
                    $virtualName = trim((string) $virtual);

                    if ($virtualName === '' || ! str_contains($virtualName, '/')) {
                        continue;
                    }

                    // First parent wins; prefer replace over provide by processing replace first.
                    $map[$virtualName] ??= $parent;
                }
            }
        }

        return $map;
    }

    /**
     * @deprecated Use parentBy()
     *
     * @return array<string, string>
     */
    public static function replacedBy(?string $installedJsonPath = null): array
    {
        return self::parentBy($installedJsonPath);
    }

    /**
     * Inherit licenses (and optional install_path / version) from the package that
     * Composer-replaces or provides a virtual stub with no install directory of its own.
     *
     * @param  list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     install_path?: string|null
     * }>  $records
     * @return list<array{
     *     name: string,
     *     version: string,
     *     licenses: list<string>,
     *     install_path?: string|null
     * }>
     */
    public static function inheritMissing(array $records, ?string $installedJsonPath = null): array
    {
        if ($records === []) {
            return [];
        }

        $map = self::parentBy($installedJsonPath);

        if ($map === []) {
            return $records;
        }

        /** @var array<string, int> $indexByName */
        $indexByName = [];

        foreach ($records as $index => $record) {
            $indexByName[$record['name']] = $index;
        }

        foreach ($records as $index => $record) {
            $parentName = $map[$record['name']] ?? null;

            if ($parentName === null || ! isset($indexByName[$parentName])) {
                continue;
            }

            $parent = $records[$indexByName[$parentName]];

            if ($record['licenses'] === [] && $parent['licenses'] !== []) {
                $records[$index]['licenses'] = $parent['licenses'];
            }

            if (
                array_key_exists('install_path', $record)
                && ($record['install_path'] === null || $record['install_path'] === '')
                && isset($parent['install_path'])
                && is_string($parent['install_path'])
                && $parent['install_path'] !== ''
            ) {
                $records[$index]['install_path'] = $parent['install_path'];
            }

            if (
                ($record['version'] === '—' || $record['version'] === '')
                && isset($parent['version'])
                && is_string($parent['version'])
                && $parent['version'] !== ''
            ) {
                $records[$index]['version'] = $parent['version'];
            }
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function packagesFromInstalledJson(?string $path): array
    {
        if ($path === null || ! File::isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed>|list<mixed> $json */
            $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (isset($json['packages']) && is_array($json['packages'])) {
            return array_values(array_filter(
                $json['packages'],
                fn (mixed $package): bool => is_array($package)
            ));
        }

        if (array_is_list($json)) {
            return array_values(array_filter(
                $json,
                fn (mixed $package): bool => is_array($package)
            ));
        }

        return [];
    }
}
