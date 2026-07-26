<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class PageAuthorizer
{
    public static function canAccess(?Authenticatable $user = null): bool
    {
        $user ??= self::resolveAuthenticatedUser();

        if ($user === null) {
            return false;
        }

        $config = config('filament-project-passport.authorization', []);

        $gateName = $config['gate_name'] ?? null;

        if (is_string($gateName) && $gateName !== '' && Gate::has($gateName)) {
            return Gate::forUser($user)->allows($gateName);
        }

        $permission = $config['permission'] ?? null;

        if (is_string($permission) && $permission !== '') {
            $hasCan = method_exists($user, 'can');
            $hasPermissionTo = method_exists($user, 'hasPermissionTo');

            if ($hasCan && $user->can($permission)) {
                return true;
            }

            if ($hasPermissionTo) {
                try {
                    return (bool) $user->hasPermissionTo($permission);
                } catch (Throwable) {
                    return false;
                }
            }

            // Permission configured but can() denied (or only can() exists).
            if ($hasCan) {
                return false;
            }
        }

        /** @var list<string> $allowedEmails */
        $allowedEmails = array_values(array_filter(
            array_map('strtolower', (array) ($config['allowed_emails'] ?? [])),
            fn (string $email): bool => $email !== ''
        ));

        if ($allowedEmails !== []) {
            $email = strtolower((string) ($user->email ?? ''));

            return in_array($email, $allowedEmails, true);
        }

        if ((bool) ($config['restricted_to_admins'] ?? true)) {
            $enforceOutsideProduction = (bool) ($config['restrict_non_production'] ?? false);

            if (app()->isProduction() || $enforceOutsideProduction) {
                return self::looksLikeAdmin($user, $config);
            }

            // Non-production and not explicitly enforced: stay open so
            // local/staging testing is not locked behind admin roles.
            return true;
        }

        return true;
    }

    /**
     * Resolve the authenticated user for the currently active Filament panel's
     * own guard when possible, falling back to the default guard.
     *
     * Some installations run a separate Filament auth guard/model (e.g. an
     * "admin" guard) alongside the application's own "web" User model. Using
     * the panel's own guard avoids checking the wrong guard's user (or a
     * stale user from a different guard) when panels are multi-guard.
     */
    private static function resolveAuthenticatedUser(): ?Authenticatable
    {
        if (class_exists(Filament::class)) {
            try {
                $panel = Filament::getCurrentPanel();

                // method_exists() (not Reflection) — this only needs a fast
                // existence check, and the installed Filament version having
                // Panel::auth() does not guarantee every supported major does.
                if ($panel !== null && method_exists($panel, 'auth')) {
                    $guardUser = $panel->auth()->user();

                    if ($guardUser !== null) {
                        return $guardUser;
                    }
                }
            } catch (Throwable) {
                // No current panel context (e.g. console) — fall back below.
            }
        }

        return auth()->user();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function looksLikeAdmin(Authenticatable $user, array $config = []): bool
    {
        if (isset($user->is_admin) && (bool) $user->is_admin) {
            return true;
        }

        if (isset($user->is_super_admin) && (bool) $user->is_super_admin) {
            return true;
        }

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            foreach (self::adminRoleNames($config) as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (Throwable) {
                    // Role package may throw if roles table is missing.
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function adminRoleNames(array $config): array
    {
        $roles = ['super_admin', 'super-admin', 'admin', 'Administrator'];

        // bezhansalleh/filament-shield allows renaming its super-admin role.
        $shieldRole = config('filament-shield.super_admin.name');

        if (is_string($shieldRole) && $shieldRole !== '') {
            $roles[] = $shieldRole;
        }

        $custom = array_map('strval', (array) ($config['admin_roles'] ?? []));

        foreach ($custom as $role) {
            if ($role !== '') {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }
}
