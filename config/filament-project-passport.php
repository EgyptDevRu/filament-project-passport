<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | Pages are registered under a dedicated group at the end of the menu.
    | group_sort is added to each page sort so the whole group stays last.
    |
    */

    'navigation' => [

        'group' => 'Developer Support',

        'group_sort' => 9990,

        'pages' => [

            'status' => [
                'label' => 'Status',
                'icon' => 'heroicon-o-shield-check',
                'sort' => 1,
            ],

            'documentation' => [
                'label' => 'Documentation',
                'icon' => 'heroicon-o-book-open',
                'sort' => 2,
            ],

            'license_audit' => [
                'label' => 'License Audit',
                'icon' => 'heroicon-o-scale',
                'sort' => 3,
            ],

            'dependency_audit' => [
                'label' => 'Dependency Audit',
                'icon' => 'heroicon-o-cube',
                'sort' => 4,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Control who can access the Developer Support pages.
    |
    | These pages expose sensitive operational data (installed package
    | versions, outdated packages, and known security advisories from
    | `composer audit`). That is effectively a vulnerability map for this
    | specific installation, so access is admin-only by default.
    |
    | Evaluation order:
    | 1. If `gate_name` is set and the gate is defined, that gate decides access.
    | 2. If `allowed_emails` is a non-empty array, only those emails may access.
    | 3. If `restricted_to_admins` is true, only admin / super-admin style users
    |    — enforced in production; see `restrict_non_production` below for
    |    local/staging environments.
    | 4. Otherwise, any authenticated Filament user may access the pages.
    |
    */

    'authorization' => [

        /*
         * Default true: only admin / super-admin style users can open the
         * Dependency Audit, License Audit, and Status pages. Set false only
         * if every authenticated user of this panel should be trusted with
         * that information — not recommended.
         *
         * Override per environment via .env (not committed):
         * FILAMENT_PROJECT_PASSPORT_RESTRICTED_TO_ADMINS=false
         */
        'restricted_to_admins' => env('FILAMENT_PROJECT_PASSPORT_RESTRICTED_TO_ADMINS', true),

        /*
         * `restricted_to_admins` above is enforced in production
         * (APP_ENV=production) only by default, so local/staging developers
         * are not locked out of these pages behind admin roles while
         * building. Set true to enforce the same admin-only restriction in
         * every environment, not just production.
         *
         * Override per environment via .env (not committed):
         * FILAMENT_PROJECT_PASSPORT_RESTRICT_NON_PRODUCTION=true
         */
        'restrict_non_production' => env('FILAMENT_PROJECT_PASSPORT_RESTRICT_NON_PRODUCTION', false),

        /*
         * Optional allow-list of email addresses. When non-empty, only users
         * whose email is in this list may access the pages.
         *
         * @var list<string>
         */
        'allowed_emails' => [
            // 'admin@example.com',
        ],

        /*
         * Optional Laravel Gate name. When set and the gate exists, it takes
         * precedence over restricted_to_admins / allowed_emails.
         *
         * Example: Gate::define('view-developer-wizard', fn ($user) => ...);
         */
        'gate_name' => null,

        /*
         * Optional Spatie Permission name. Checked when the user has a
         * `can()` / `hasPermissionTo()` method (e.g. Spatie laravel-permission,
         * including bezhansalleh/filament-shield, which is built on top of it).
         */
        'permission' => null,

        /*
         * Extra role names accepted by the built-in "admin-like" heuristic
         * used when `restricted_to_admins` is true (checked via `hasRole()`).
         * The default names already covered are: super_admin, super-admin,
         * admin, Administrator — filament-shield's default super-admin role
         * name is included automatically. Add custom names for legacy or
         * multi-guard installs with a differently named admin role.
         *
         * @var list<string>
         */
        'admin_roles' => [
            // 'staff',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation
    |--------------------------------------------------------------------------
    |
    | Local Markdown documentation is loaded from a `.docs` directory in the
    | root of the consuming Laravel application (base_path('.docs')).
    |
    | The Documentation page is only available when the license check returns
    | an official copy for the current domain.
    |
    | By default, documentation content is shown in production only so
    | non-production installs cannot browse or copy shipped docs. The menu
    | item still appears when the license allows it; only the page body is
    | replaced with a placeholder. Set allow_non_production to true to
    | override (e.g. local preview while building docs).
    |
    */

    'docs' => [

        'directory' => '.docs',

        /*
         * Override per environment via .env (not committed):
         * FILAMENT_PROJECT_PASSPORT_DOCS_ENABLED=false
         */
        'enabled' => env('FILAMENT_PROJECT_PASSPORT_DOCS_ENABLED', true),

        /*
         * When false (default), documentation content is hidden outside
         * APP_ENV=production. Set true to allow content in local/staging.
         *
         * Override per environment via .env (not committed):
         * FILAMENT_PROJECT_PASSPORT_DOCS_ALLOW_NON_PRODUCTION=true
         */
        'allow_non_production' => env('FILAMENT_PROJECT_PASSPORT_DOCS_ALLOW_NON_PRODUCTION', false),

        /*
         * Mermaid diagram renderer, loaded from a CDN with Subresource
         * Integrity so the fetched file cannot be tampered with in transit.
         * `integrity` must always match the exact file published under
         * `version` — bumping one without the other breaks diagram
         * rendering (the browser refuses to run a script that fails its
         * integrity check). Regenerate via:
         * `curl -s https://cdn.jsdelivr.net/npm/mermaid@<version>/dist/mermaid.min.js | openssl dgst -sha384 -binary | openssl base64 -A`
         */
        'mermaid' => [
            'version' => '11.16.0',
            'integrity' => 'sha384-T/0lMUdJpd2S1ZHtRiofG3htU3xPCrFVeAQ1UUE2TJwlEJSV5NUwn30kP28n238E',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Dependency audit
    |--------------------------------------------------------------------------
    |
    | Cold scans run Composer outdated/audit outside the Livewire request
    | (queue worker when available, otherwise a detached artisan process).
    | The page only reads cache and polls — same idea as Filament deferLoading.
    |
    */

    'dependency_audit' => [

        /*
         * When true and queue.default is not "sync", dispatch RefreshDependencyAuditJob.
         * Requires `php artisan queue:work` (or Horizon) in the host app.
         *
         * Override per environment via .env (not committed):
         * FILAMENT_PROJECT_PASSPORT_DEPENDENCY_AUDIT_USE_QUEUE=false
         */
        'use_queue' => env('FILAMENT_PROJECT_PASSPORT_DEPENDENCY_AUDIT_USE_QUEUE', true),

    ],

];
