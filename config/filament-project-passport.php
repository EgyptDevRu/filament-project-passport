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
    | Evaluation order:
    | 1. If `gate_name` is set and the gate is defined, that gate decides access.
    | 2. If `allowed_emails` is a non-empty array, only those emails may access.
    | 3. If `restricted_to_admins` is true, only admin / super-admin style users.
    | 4. Otherwise, any authenticated Filament user may access the pages.
    |
    */

    'authorization' => [

        /*
         * Default false so any authenticated Filament user can open the pages
         * out of the box. Set true (and/or use allowed_emails / gate_name) to
         * lock them down in production.
         */
        'restricted_to_admins' => false,

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
         * `can()` / `hasPermissionTo()` method (e.g. Spatie laravel-permission).
         */
        'permission' => null,

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
    */

    'docs' => [

        'directory' => '.docs',

        'enabled' => true,

    ],

];
