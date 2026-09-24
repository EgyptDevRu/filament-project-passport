<?php

use EgyptDevRu\FilamentProjectPassport\Pages\DependencyAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage;
use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\OpenSourceLicensesPage;
use EgyptDevRu\FilamentProjectPassport\Pages\StatusPage;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use EgyptDevRu\FilamentProjectPassport\Support\ShieldIntegration;
use Illuminate\Contracts\Auth\Authenticatable;

function makePermissionUser(string $email, array $permissions, bool $isAdmin = false): Authenticatable
{
    return new class($email, $permissions, $isAdmin) implements Authenticatable
    {
        /**
         * @param  list<string>  $permissions
         */
        public function __construct(
            public string $email,
            public array $permissions,
            public bool $is_admin,
        ) {}

        public function can(string $ability): bool
        {
            return in_array($ability, $this->permissions, true);
        }

        public function hasPermissionTo(string $permission): bool
        {
            return in_array($permission, $this->permissions, true);
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

it('does not treat shield as installed in this package test suite', function () {
    expect(ShieldIntegration::isInstalled())->toBeFalse()
        ->and(ShieldIntegration::permissionKey())->toBeNull();
});

it('uses an explicit permission key when configured', function () {
    config()->set('filament-project-passport.authorization.permission', 'Access:Passport');

    expect(ShieldIntegration::permissionIsExplicit())->toBeTrue()
        ->and(ShieldIntegration::permissionKey())->toBe('Access:Passport');
});

it('excludes passport pages and registers one custom shield permission', function () {
    config()->set('filament-shield.pages.exclude', ['App\\Filament\\Pages\\Dashboard']);
    config()->set('filament-shield.exclude.pages', ['Dashboard']);
    config()->set('filament-shield.custom_permissions', []);
    config()->set('filament-shield.shield_resource.tabs', [
        'pages' => true,
        'widgets' => true,
        'resources' => true,
        'custom_permissions' => false,
    ]);
    config()->set('filament-shield.entities', [
        'pages' => true,
        'custom_permissions' => false,
    ]);

    ShieldIntegration::apply();

    $v4Exclude = config('filament-shield.pages.exclude');
    $v3Exclude = config('filament-shield.exclude.pages');

    foreach ([
        StatusPage::class,
        DocumentationPage::class,
        LicenseAuditPage::class,
        DependencyAuditPage::class,
        OpenSourceLicensesPage::class,
        'StatusPage',
        'DocumentationPage',
        'LicenseAuditPage',
        'DependencyAuditPage',
        'OpenSourceLicensesPage',
    ] as $id) {
        expect($v4Exclude)->toContain($id)
            ->and($v3Exclude)->toContain($id);
    }

    expect($v4Exclude)->toContain('App\\Filament\\Pages\\Dashboard')
        ->and($v3Exclude)->toContain('Dashboard')
        ->and(config('filament-shield.custom_permissions'))->toHaveKey(ShieldIntegration::DEFAULT_PERMISSION)
        ->and(config('filament-shield.custom_permissions')[ShieldIntegration::DEFAULT_PERMISSION])
        ->toBe('[Package] Developer Support')
        ->and(config('filament-shield.shield_resource.tabs.custom_permissions'))->toBeTrue()
        ->and(config('filament-shield.entities.custom_permissions'))->toBeTrue();
});

it('registers a customized permission key with shield instead of the default', function () {
    config()->set('filament-project-passport.authorization.permission', 'View:StudioSupport');
    config()->set('filament-project-passport.authorization.permission_label', 'Studio Support');
    config()->set('filament-shield.custom_permissions', []);
    config()->set('filament-shield.pages.exclude', []);

    ShieldIntegration::apply();

    expect(config('filament-shield.custom_permissions'))->toHaveKey('View:StudioSupport')
        ->and(config('filament-shield.custom_permissions')['View:StudioSupport'])->toBe('Studio Support')
        ->and(config('filament-shield.custom_permissions'))->not->toHaveKey(ShieldIntegration::DEFAULT_PERMISSION);
});

it('does not overwrite a host-defined shield custom permission label', function () {
    config()->set('filament-shield.custom_permissions', [
        ShieldIntegration::DEFAULT_PERMISSION => 'Host Label',
    ]);
    config()->set('filament-shield.pages.exclude', []);

    ShieldIntegration::apply();

    expect(config('filament-shield.custom_permissions')[ShieldIntegration::DEFAULT_PERMISSION])->toBe('Host Label');
});

it('allows access when the user has the configured permission', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => true,
        'restrict_non_production' => true,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => 'View:DeveloperSupport',
    ]);

    $user = makePermissionUser('user@example.com', ['View:DeveloperSupport'], isAdmin: false);

    expect(PageAuthorizer::canAccess($user))->toBeTrue();
});

it('denies access when a permission is configured and the user lacks it', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => true,
        'restrict_non_production' => true,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => 'View:DeveloperSupport',
    ]);

    $user = makePermissionUser('admin@example.com', [], isAdmin: true);

    expect(PageAuthorizer::canAccess($user))->toBeFalse();
});

it('falls through to admin rules when no permission is set and shield is absent', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => true,
        'restrict_non_production' => true,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => null,
    ]);

    $admin = makePermissionUser('admin@example.com', [], isAdmin: true);
    $user = makePermissionUser('user@example.com', [], isAdmin: false);

    expect(PageAuthorizer::canAccess($admin))->toBeTrue()
        ->and(PageAuthorizer::canAccess($user))->toBeFalse();
});
