<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class PageAuthorizer
{
    public static function canAccess(?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();

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
            if (method_exists($user, 'can') && $user->can($permission)) {
                return true;
            }

            if (method_exists($user, 'hasPermissionTo')) {
                try {
                    return (bool) $user->hasPermissionTo($permission);
                } catch (\Throwable) {
                    return false;
                }
            }

            // Permission configured but user cannot be checked → deny.
            if (method_exists($user, 'can') || method_exists($user, 'hasPermissionTo')) {
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
            return self::looksLikeAdmin($user);
        }

        return true;
    }

    private static function looksLikeAdmin(Authenticatable $user): bool
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
            foreach (['super_admin', 'super-admin', 'admin', 'Administrator'] as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (\Throwable) {
                    // Role package may throw if roles table is missing.
                }
            }
        }

        return false;
    }
}
