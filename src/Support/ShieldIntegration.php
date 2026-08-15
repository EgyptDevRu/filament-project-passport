<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use EgyptDevRu\FilamentProjectPassport\FilamentProjectPassportServiceProvider;
use EgyptDevRu\FilamentProjectPassport\Pages\DependencyAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage;
use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\StatusPage;
use Throwable;

/**
 * One Shield custom permission for Developer Support; the four Filament pages
 * are excluded from Shield page discovery so they do not invent unused keys.
 */
final class ShieldIntegration
{
    public const string DEFAULT_PERMISSION = 'View:DeveloperSupport';

    public const string DEFAULT_PERMISSION_LABEL = '[Package] Developer Support';

    /**
     * @var list<class-string>
     */
    public const array PAGE_CLASSES = [
        StatusPage::class,
        DocumentationPage::class,
        LicenseAuditPage::class,
        DependencyAuditPage::class,
    ];

    public static function isInstalled(): bool
    {
        return class_exists('BezhanSalleh\\FilamentShield\\FilamentShield')
            || class_exists('BezhanSalleh\\FilamentShield\\FilamentShieldPlugin')
            || class_exists('BezhanSalleh\\FilamentShield\\Facades\\FilamentShield');
    }

    /**
     * Merge exclusions and the single custom permission into Shield config.
     * Idempotent. No-ops when Shield is not installed.
     */
    public static function register(): void
    {
        if (! self::isInstalled()) {
            return;
        }

        self::apply();
    }

    /**
     * Write Shield config keys. Public so tests can exercise the merge without
     * requiring bezhansalleh/filament-shield as a package dependency.
     */
    public static function apply(): void
    {
        self::excludeDiscoveredPages();
        self::registerCustomPermission();
        self::enableCustomPermissionTab();
    }

    /**
     * Spatie / Shield permission key to check, or null when Spatie should be skipped.
     *
     * Explicit `authorization.permission` always wins. Otherwise the default
     * Shield key is used only when Shield is installed.
     */
    public static function permissionKey(): ?string
    {
        $configured = config('filament-project-passport.authorization.permission');

        if (is_string($configured) && $configured !== '') {
            return self::resolveFormattedKey($configured);
        }

        if (! self::isInstalled()) {
            return null;
        }

        return self::resolveFormattedKey(self::DEFAULT_PERMISSION);
    }

    /**
     * True when the host explicitly set `authorization.permission`.
     */
    public static function permissionIsExplicit(): bool
    {
        $configured = config('filament-project-passport.authorization.permission');

        return is_string($configured) && $configured !== '';
    }

    public static function permissionLabel(): string
    {
        $label = config('filament-project-passport.authorization.permission_label');

        return is_string($label) && $label !== '' ? $label : self::DEFAULT_PERMISSION_LABEL;
    }

    /**
     * Whether Spatie already has this permission in its store (after shield:generate).
     */
    public static function permissionExistsInStore(string $name): bool
    {
        try {
            $registrar = 'Spatie\\Permission\\PermissionRegistrar';

            if (! class_exists($registrar)) {
                return false;
            }

            $model = app($registrar)->getPermissionClass();

            if (! is_string($model) || ! class_exists($model)) {
                return false;
            }

            return $model::query()->where('name', $name)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<class-string|string>
     */
    public static function pageIdentifiers(): array
    {
        $pages = class_exists(FilamentProjectPassportServiceProvider::class)
            ? FilamentProjectPassportServiceProvider::PAGES
            : self::PAGE_CLASSES;

        $ids = [];

        foreach ($pages as $page) {
            $ids[] = $page;
            $ids[] = class_basename($page);
        }

        return array_values(array_unique($ids));
    }

    private static function excludeDiscoveredPages(): void
    {
        $identifiers = self::pageIdentifiers();

        self::mergeList('filament-shield.pages.exclude', $identifiers);
        self::mergeList('filament-shield.exclude.pages', $identifiers);
    }

    private static function registerCustomPermission(): void
    {
        $key = self::configuredOrDefaultPermissionKey();
        $label = self::permissionLabel();
        $existing = config('filament-shield.custom_permissions');

        if (! is_array($existing)) {
            $existing = [];
        }

        if (! array_key_exists($key, $existing)) {
            $existing[$key] = $label;
            config(['filament-shield.custom_permissions' => $existing]);
        }
    }

    private static function enableCustomPermissionTab(): void
    {
        if (is_array(config('filament-shield.shield_resource.tabs'))) {
            config(['filament-shield.shield_resource.tabs.custom_permissions' => true]);
        }

        if (is_array(config('filament-shield.entities'))) {
            config(['filament-shield.entities.custom_permissions' => true]);
        }
    }

    private static function configuredOrDefaultPermissionKey(): string
    {
        $configured = config('filament-project-passport.authorization.permission');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::DEFAULT_PERMISSION;
    }

    /**
     * Prefer the key Shield actually stores after its formatter runs.
     */
    private static function resolveFormattedKey(string $key): string
    {
        try {
            $facade = 'BezhanSalleh\\FilamentShield\\Facades\\FilamentShield';

            if (! class_exists($facade)) {
                return $key;
            }

            /** @var array<string, mixed>|null $custom */
            $custom = $facade::getCustomPermissions();

            if (! is_array($custom) || $custom === []) {
                return $key;
            }

            if (array_key_exists($key, $custom)) {
                return $key;
            }

            $label = self::permissionLabel();

            foreach ($custom as $formatted => $customLabel) {
                if ((string) $customLabel === $label || strcasecmp((string) $formatted, $key) === 0) {
                    return (string) $formatted;
                }
            }
        } catch (Throwable) {
            // Shield facade may be unavailable during early boot.
        }

        return $key;
    }

    /**
     * @param  list<class-string|string>  $values
     */
    private static function mergeList(string $configKey, array $values): void
    {
        $existing = config($configKey);

        if (! is_array($existing)) {
            return;
        }

        config([$configKey => array_values(array_unique([...$existing, ...$values]))]);
    }
}
