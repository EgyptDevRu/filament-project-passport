<?php

use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function makeAuthUser(string $email = 'user@example.com', bool $isAdmin = false): Authenticatable
{
    return new class($email, $isAdmin) implements Authenticatable
    {
        public function __construct(
            public string $email,
            public bool $is_admin,
        ) {}

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

it('resolves the license endpoint outside of config', function () {
    $client = new LicenseApiClient;
    $reflection = new ReflectionClass($client);

    expect($reflection->hasConstant('API_BASE_DOMAIN'))->toBeFalse()
        ->and($reflection->hasConstant('API_ENDPOINT_PATH'))->toBeFalse()
        ->and($client->endpoint())->toBe('https://en.egyptdev.ru/api/v1/license-check')
        ->and(\EgyptDevRu\FilamentProjectPassport\Support\LicenseApiGateway::origin())->toBe('https://en.egyptdev.ru')
        ->and(config('filament-project-passport'))->not->toHaveKey('api_url')
        ->and(config('filament-project-passport'))->not->toHaveKey('api_domain')
        ->and(config('filament-project-passport'))->not->toHaveKey('license_endpoint');
});

it('caches license responses per host for twelve hours', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => true,
            'developer_name' => 'Example Agency',
            'email' => 'agency@example.com',
            'phone' => '+10000000000',
            'support_warranty_until' => '2027-01-01',
            'client_name' => 'Example Client',
            'project_name' => 'Example Project',
        ], 200),
    ]);

    config()->set('app.url', 'https://app.test');

    $client = app(LicenseApiClient::class);
    $first = $client->fetch();
    $second = $client->fetch();

    expect($first['is_official'])->toBeTrue()
        ->and($first['from_cache'])->toBeFalse()
        ->and($second['from_cache'])->toBeTrue()
        ->and($second['project_name'])->toBe('Example Project');

    Http::assertSentCount(1);
});

it('fails gracefully when the license api is unreachable', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response('Server Error', 500),
    ]);

    config()->set('app.url', 'https://app.test');

    $result = app(LicenseApiClient::class)->fetch();

    expect($result['is_official'])->toBeFalse()
        ->and($result['request_failed'])->toBeTrue()
        ->and($result['error_code'])->toBe(\EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode::HTTP_500)
        ->and($result['project_name'])->toBeNull()
        ->and(app(LicenseApiClient::class)->allowsDocumentation())->toBeFalse();
});

it('marks cloudflare failures as undefined status with error codes', function () {
    config()->set('app.url', 'https://app.test');

    Http::fake([
        'en.egyptdev.ru/*' => Http::response('<html>cloudflare cf-ray</html>', 403),
    ]);

    $cloudflare = app(LicenseApiClient::class)->fetch();

    expect($cloudflare['request_failed'])->toBeTrue()
        ->and($cloudflare['error_code'])->toBe(\EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode::CF_BLOCK)
        ->and(\EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode::isUndefinedStatus($cloudflare))->toBeTrue()
        ->and(app(LicenseApiClient::class)->allowsDocumentation())->toBeFalse();
});

it('marks invalid json responses as undefined status', function () {
    config()->set('app.url', 'https://app.test');

    Http::fake([
        'en.egyptdev.ru/*' => Http::response('not-json', 200),
    ]);

    $invalidJson = app(LicenseApiClient::class)->fetch();

    expect($invalidJson['error_code'])->toBe(\EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode::JSON_INVALID)
        ->and($invalidJson['request_failed'])->toBeTrue()
        ->and(app(LicenseApiClient::class)->allowsDocumentation())->toBeFalse();
});

it('caches failed license checks so the api is not hammered', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response('Server Error', 500),
    ]);

    config()->set('app.url', 'https://app.test');

    $client = app(LicenseApiClient::class);
    $first = $client->fetch();
    $second = $client->fetch();

    expect($first['request_failed'])->toBeTrue()
        ->and($second['from_cache'])->toBeTrue()
        ->and($second['request_failed'])->toBeTrue();

    Http::assertSentCount(1);
});

it('treats string and integer official flags as boolean true', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => 'true',
            'project_name' => 'Example Project',
        ], 200),
    ]);

    config()->set('app.url', 'https://app.test');

    expect(app(LicenseApiClient::class)->fetch()['is_official'])->toBeTrue();
});

it('can bust the license cache', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::sequence()
            ->push(['is_official' => true, 'project_name' => 'Project One'], 200)
            ->push(['is_official' => true, 'project_name' => 'Project Two'], 200),
    ]);

    config()->set('app.url', 'https://app.test');

    $client = app(LicenseApiClient::class);
    expect($client->fetch()['project_name'])->toBe('Project One');

    $refreshed = $client->refresh();

    expect($refreshed['project_name'])->toBe('Project Two')
        ->and($refreshed['from_cache'])->toBeFalse();

    Http::assertSentCount(2);
});

it('rewrites internal markdown links for in-viewer navigation', function () {
    $docs = base_path('.docs');

    File::makeDirectory($docs.'/guides', 0755, true);
    File::put($docs.'/index.md', "[Deploy](guides/deploy.md)\n\n[Missing](./nope.md)\n\n[External](https://example.com)");
    File::put($docs.'/guides/deploy.md', "# Deploy\n\n[Back](../index.md)");

    $scanner = app(DocumentationScanner::class);
    $indexKey = md5('index.md');
    $deployKey = md5('guides/deploy.md');

    $html = $scanner->renderByKey($indexKey);

    expect($html)->toContain('data-doc-key="'.$deployKey.'"')
        ->and($html)->toContain('fi-pp-doc-link')
        ->and($html)->toContain('fi-pp-doc-link-disabled')
        ->and($html)->toContain('target="_blank"')
        ->and($html)->not->toContain('href="guides/deploy.md"');

    File::deleteDirectory($docs);
});

it('does not read markdown outside the configured docs directory via path prefix', function () {
    $docs = base_path('.docs');
    $evil = base_path('.docs-evil');

    File::makeDirectory($docs, 0755, true);
    File::makeDirectory($evil, 0755, true);
    File::put($docs.'/safe.md', '# Safe');
    File::put($evil.'/secret.md', '# Secret');

    $scanner = app(DocumentationScanner::class);

    expect($scanner->renderFile($evil.'/secret.md'))->toBe('')
        ->and($scanner->renderByKey(md5('secret.md')))->toBeNull();

    File::deleteDirectory($docs);
    File::deleteDirectory($evil);
});

it('keeps the docs directory under the application root', function () {
    config()->set('filament-project-passport.docs.directory', '../../etc');

    $scanner = app(DocumentationScanner::class);
    $docsPath = realpath($scanner->docsPath()) ?: $scanner->docsPath();
    $base = realpath(base_path()) ?: base_path();

    expect(str_starts_with(
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $docsPath),
        rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
    ) || $docsPath === $base)->toBeTrue();
});

it('transforms mermaid fenced blocks into renderable diagram containers', function () {
    $docs = base_path('.docs');

    File::makeDirectory($docs, 0755, true);
    File::put($docs.'/diagrams.md', <<<'MD'
# Diagrams

```mermaid
sequenceDiagram
    Alice->>Bob: Hello
```

```
flowchart TD
    A --> B
```

```php
echo 'not mermaid';
```
MD);

    $scanner = app(DocumentationScanner::class);
    $html = $scanner->renderByKey(md5('diagrams.md'));

    expect($html)->toContain('class="fi-pp-mermaid mermaid"')
        ->and($html)->toContain('sequenceDiagram')
        ->and($html)->toContain('flowchart TD')
        ->and($html)->toContain("echo 'not mermaid';")
        ->and($html)->toContain('language-php')
        ->and(substr_count($html, 'fi-pp-mermaid'))->toBe(2)
        ->and($html)->not->toContain('language-mermaid');

    File::deleteDirectory($docs);
});

it('builds a nested navigation tree for subfolders', function () {
    $docs = base_path('.docs');

    File::makeDirectory($docs.'/API/nested', 0755, true);
    File::put($docs.'/00_index.md', "# Index");
    File::put($docs.'/API/01_overview.md', "# Overview");
    File::put($docs.'/API/nested/deep.md', "# Deep");

    $scanner = app(DocumentationScanner::class);
    $tree = $scanner->navigationTree();

    expect($tree)->toHaveCount(2)
        ->and($tree[0]['type'])->toBe('file')
        ->and($tree[0]['relative'])->toBe('00_index.md')
        ->and($tree[1]['type'])->toBe('folder')
        ->and($tree[1]['title'])->toBe('API')
        ->and($tree[1]['children'])->toHaveCount(2)
        ->and($tree[1]['children'][0]['type'])->toBe('file')
        ->and($tree[1]['children'][0]['relative'])->toBe('API/01_overview.md')
        ->and($tree[1]['children'][1]['type'])->toBe('folder')
        ->and($tree[1]['children'][1]['children'][0]['relative'])->toBe('API/nested/deep.md');

    File::deleteDirectory($docs);
});

it('hides documentation when the license is unofficial', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => false,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => null,
    ]);
    config()->set('app.url', 'https://app.test');

    $user = makeAuthUser('user@example.com');
    auth()->login($user);

    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => false,
        ], 200),
    ]);

    expect(app(LicenseApiClient::class)->isOfficial())->toBeFalse()
        ->and(\EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage::canAccess())->toBeFalse()
        ->and(\EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage::getNavigationItems())->toBe([]);
});

it('shows documentation when the license is official', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => false,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => null,
    ]);
    config()->set('app.url', 'https://app.test');

    $user = makeAuthUser('user@example.com');
    auth()->login($user);

    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => true,
            'project_name' => 'Example Project',
        ], 200),
    ]);

    expect(app(LicenseApiClient::class)->isOfficial())->toBeTrue()
        ->and(\EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage::canAccess())->toBeTrue();
});

it('keeps documentation available when domain is verified but warranties expired', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => false,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => null,
    ]);
    config()->set('app.url', 'https://app.test');

    $user = makeAuthUser('user@example.com');
    auth()->login($user);

    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => true,
            'support_warranty_until' => '2020-01-01',
            'extended_support_warranty_until' => '2020-06-01',
            'project_name' => 'Example Project',
        ], 200),
    ]);

    $license = app(LicenseApiClient::class)->fetch();

    expect($license['is_official'])->toBeTrue()
        ->and($license['extended_support_warranty_until'])->toBe('2020-06-01')
        ->and(\EgyptDevRu\FilamentProjectPassport\Support\SupportCoverage::isSupportActive($license))->toBeFalse()
        ->and(\EgyptDevRu\FilamentProjectPassport\Support\SupportCoverage::isVerifiedWithoutActiveSupport($license))->toBeTrue()
        ->and(\EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage::canAccess())->toBeTrue();
});

it('treats extended warranty as active egyptdev support', function () {
    $license = [
        'is_official' => true,
        'support_warranty_until' => '2020-01-01',
        'extended_support_warranty_until' => now()->addMonth()->toDateString(),
    ];

    expect(\EgyptDevRu\FilamentProjectPassport\Support\SupportCoverage::isSupportActive($license))->toBeTrue()
        ->and(\EgyptDevRu\FilamentProjectPassport\Support\SupportCoverage::isDomainVerified($license))->toBeTrue();
});

it('denies page access when restricted and user is not an admin', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => true,
        'allowed_emails' => [],
        'gate_name' => null,
        'permission' => null,
    ]);

    $user = makeAuthUser('user@example.com', isAdmin: false);

    expect(PageAuthorizer::canAccess($user))->toBeFalse();
});

it('allows page access for allow-listed emails', function () {
    config()->set('filament-project-passport.authorization', [
        'restricted_to_admins' => true,
        'allowed_emails' => ['admin@example.com'],
        'gate_name' => null,
        'permission' => null,
    ]);

    $user = makeAuthUser('admin@example.com');

    expect(PageAuthorizer::canAccess($user))->toBeTrue();
});

it('exposes a developer log payload for the status easter egg', function () {
    Http::fake([
        'en.egyptdev.ru/*' => Http::response([
            'is_official' => true,
            'project_name' => 'Example Project',
        ], 200),
    ]);

    config()->set('app.url', 'https://app.test');

    $page = app(\EgyptDevRu\FilamentProjectPassport\Pages\StatusPage::class);
    $page->license = app(LicenseApiClient::class)->fetch();

    $log = $page->getDeveloperLog();

    expect($log)->toHaveKeys(['opened_at', 'application_host', 'cache_key', 'endpoint', 'api_response'])
        ->and($log['api_response']['project_name'])->toBe('Example Project')
        ->and($log['endpoint'])->toBe('https://en.egyptdev.ru/api/v1/license-check');
});
