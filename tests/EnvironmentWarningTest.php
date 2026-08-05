<?php

use EgyptDevRu\FilamentProjectPassport\Support\EnvironmentWarning;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('does not warn in production or testing environments', function () {
    $original = app()->environment();

    try {
        app()['env'] = 'production';
        expect(EnvironmentWarning::shouldWarn())->toBeFalse();

        app()['env'] = 'testing';
        expect(EnvironmentWarning::shouldWarn())->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});

it('warns in local and staging environments', function () {
    $original = app()->environment();

    try {
        app()['env'] = 'local';
        expect(EnvironmentWarning::shouldWarn())->toBeTrue()
            ->and(EnvironmentWarning::environmentLabel())->toBe('local');

        app()['env'] = 'staging';
        expect(EnvironmentWarning::shouldWarn())->toBeTrue()
            ->and(EnvironmentWarning::title())->toBe('Non-production environment')
            ->and(EnvironmentWarning::message())->toContain('production-ready');
    } finally {
        app()['env'] = $original;
    }
});

it('sanitizes environment labels and installation hosts for display', function () {
    $original = app()->environment();

    try {
        app()['env'] = 'stg_01-beta';
        expect(EnvironmentWarning::environmentLabel())->toBe('stg_01-beta');

        app()['env'] = 'local<script>alert(1)</script>';
        expect(EnvironmentWarning::environmentLabel())->toBe('localscriptalert1script')
            ->and(EnvironmentWarning::environmentLabel())->not->toContain('<')
            ->and(EnvironmentWarning::environmentLabel())->not->toContain('>');

        $request = Request::create('https://panel.example.test/admin', 'GET');
        app()->instance('request', $request);
        expect(EnvironmentWarning::installationDomain())->toBe('PANEL.EXAMPLE.TEST');

        $poisoned = Request::create('https://example.test/', 'GET');
        $poisoned->headers->set('HOST', 'evil.test<script>');
        app()->instance('request', $poisoned);

        expect(EnvironmentWarning::installationDomain())->toBe('');
    } finally {
        app()['env'] = $original;
    }
});

it('does not surface the warning while app environment is testing', function () {
    $original = app()->environment();

    try {
        app()['env'] = 'testing';

        EnvironmentWarning::markPendingAfterLogin();
        expect(session()->has(EnvironmentWarning::SESSION_KEY))->toBeFalse()
            ->and(EnvironmentWarning::isPending())->toBeFalse();

        session([EnvironmentWarning::SESSION_KEY => true]);
        expect(EnvironmentWarning::isPending())->toBeFalse();

        EnvironmentWarning::dismiss();
        expect(session()->has(EnvironmentWarning::SESSION_KEY))->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});

it('listens for the laravel login event', function () {
    expect(Event::hasListeners(Login::class))->toBeTrue();
});

it('returns not found for direct get visits to the dismiss uri', function () {
    $this->get('/filament-project-passport/dismiss-environment-warning')
        ->assertNotFound();
});
