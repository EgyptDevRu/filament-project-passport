<?php

it('renders an environment badge outside production', function () {
    expect(app()->isProduction())->toBeFalse();

    $html = view('filament-project-passport::components.environment-badge')->render();

    expect($html)->toContain('fi-pp-env-badge')
        ->and($html)->toContain('fi-pp-badge--warning')
        ->and($html)->toContain(e(app()->environment()));
});

it('hides the environment badge in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(app()->isProduction())->toBeTrue();

    $html = view('filament-project-passport::components.environment-badge')->render();

    expect(trim($html))->toBe('');
});
