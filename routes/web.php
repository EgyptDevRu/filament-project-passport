<?php

use EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

$path = '/filament-project-passport/dismiss-environment-warning';

// Direct browser visits must not raise MethodNotAllowed — treat as missing.
Route::get($path, static fn () => abort(404))->middleware(['web']);

Route::post($path, function () {
    $authenticated = auth()->check();

    try {
        if (! $authenticated && class_exists(Filament::class) && Filament::auth()->check()) {
            $authenticated = true;
        }
    } catch (Throwable) {
        // Panel auth may be unavailable outside a Filament request.
    }

    if (! $authenticated) {
        foreach (array_keys(config('auth.guards', [])) as $guard) {
            if (auth($guard)->check()) {
                $authenticated = true;
                break;
            }
        }
    }

    abort_unless($authenticated, 401);

    EnvironmentWarning::dismiss();

    if (request()->expectsJson() || request()->ajax()) {
        return response()->json(['ok' => true]);
    }

    return back();
})->middleware(['web'])->name('filament-project-passport.dismiss-environment-warning');
