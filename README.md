# Filament Project Passport

[![Latest Version on Packagist](https://img.shields.io/packagist/v/egyptdevru/filament-project-passport.svg?style=flat-square)](https://packagist.org/packages/egyptdevru/filament-project-passport)
[![Total Downloads](https://img.shields.io/packagist/dt/egyptdevru/filament-project-passport.svg?style=flat-square)](https://packagist.org/packages/egyptdevru/filament-project-passport)

Built for **existing EgyptDev Studio customers**.

Support status, documentation, and other studio-backed features only work when this install has an **active EgyptDev Studio contract**. You can still install the package without one, but those features stay locked.

## Features

- Auto-registers a **Developer Support** navigation group (Status + Documentation) on every Filament panel — no `PanelProvider` edits required
- Support Status page for EgyptDev development / maintenance / support coverage
- Recursive `.docs/**/*.md` viewer with sidebar navigation, Mermaid diagrams, and in-viewer Markdown links
- Configurable navigation label/icon/sort/group and authorization rules
- License Audit for Composer package commercial-license compatibility
- Dependency Audit for outdated packages, security advisories, Laravel & Filament

## Requirements

- PHP 8.3+
- Laravel 10 / 11 / 12+
- Filament `^3.0` | `^4.0` | `^5.0`

## Installation

```bash
composer require egyptdevru/filament-project-passport:"*"
```

This installs with a `*` version constraint so Composer always resolves the latest release on update.

Publish the config (UI + authorization only):

```bash
php artisan vendor:publish --tag="filament-project-passport-config"
```

Optionally publish views:

```bash
php artisan vendor:publish --tag="filament-project-passport-views"
```

After install, open any Filament panel — the **Developer Support** group appears at the end of the navigation with **Status**, **Documentation**, **License Audit**, and **Dependency Audit**.

Artisan commands and their schedule entries are registered by the package automatically. You do **not** need to add them to your app’s `routes/console.php` / `Kernel` schedule. You only need Laravel’s normal scheduler cron on the server (see below).

## Configuration

### Per-environment overrides via `.env`

The most commonly environment-specific settings can be overridden with `.env` variables instead of editing the published config file, so a local-only tweak (e.g. previewing docs on your machine) never gets committed and rolled out to staging/production by accident:

| `.env` variable                                        | Config key                              | Default |
|--------------------------------------------------------|-----------------------------------------|---------|
| `FILAMENT_PROJECT_PASSPORT_RESTRICTED_TO_ADMINS`       | `authorization.restricted_to_admins`    | `true`  |
| `FILAMENT_PROJECT_PASSPORT_RESTRICT_NON_PRODUCTION`    | `authorization.restrict_non_production` | `false` |
| `FILAMENT_PROJECT_PASSPORT_DOCS_ENABLED`               | `docs.enabled`                          | `true`  |
| `FILAMENT_PROJECT_PASSPORT_DOCS_ALLOW_NON_PRODUCTION`  | `docs.allow_non_production`             | `false` |
| `FILAMENT_PROJECT_PASSPORT_DEPENDENCY_AUDIT_USE_QUEUE` | `dependency_audit.use_queue`            | `true`  |

For example, to preview documentation content locally without touching the committed config:

```env
# .env (local only, not committed)
FILAMENT_PROJECT_PASSPORT_DOCS_ALLOW_NON_PRODUCTION=true
```

If you run `php artisan config:cache`, re-run it after changing any of these `.env` values.

### Authorization evaluation order

1. If `gate_name` is set and defined, that Gate decides access
2. If `permission` is set, Spatie / `can()` is checked
3. If `allowed_emails` is non-empty, only those emails may access
4. If `restricted_to_admins` is `true`, only admin-like users (flags / roles) may access
5. Otherwise, any authenticated Filament user may access

## Local documentation

Place Markdown files in your application root:

```text
.docs/
  getting-started.md
  deployment/
    checklist.md
```

They appear in a sidebar on the **Documentation** page. Documentation is shown only when EgyptDev support status allows it.

## Scheduled audits

These commands are auto-registered with the package:

```bash
# Refresh Composer dependency audit (skips when cache is less than 7 days; Sundays always refresh)
php artisan filament-project-passport:refresh-dependency-audit

# Force dependency audit refresh
php artisan filament-project-passport:refresh-dependency-audit --force

# Refresh Composer license audit (skips when cache is younger than 14 days)
php artisan filament-project-passport:refresh-license-audit

# Force license audit refresh
php artisan filament-project-passport:refresh-license-audit --force
```

The package also schedules them automatically (app timezone):

| Job                                                  | When                                                                                                    |
|------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| `filament-project-passport:refresh-dependency-audit` | Every day at `03:00` (Sunday always refreshes; other days only if the cache is missing or ≥ 7 days old) |
| `filament-project-passport:refresh-license-audit`    | Every day at `03:00` (runs a refresh only if the cache is missing or ≥ 14 days old)                     |

Dependency Audit invokes Composer via project `composer.phar` (if present) or the global `composer` binary. Place a `composer.phar` in the application root when the server has no global Composer.

Ensure the host application runs Laravel’s scheduler, for example:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Roman Chutchev](https://github.com/RChutchev)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
