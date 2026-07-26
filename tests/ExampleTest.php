<?php

it('loads the package config', function () {
    expect(config('filament-project-passport.navigation.group'))->toBe('Developer Support')
        ->and(config('filament-project-passport.navigation.pages.status.label'))->toBe('Status')
        ->and(config('filament-project-passport.navigation.pages.documentation.label'))->toBe('Documentation')
        ->and(config('filament-project-passport.authorization.restricted_to_admins'))->toBeFalse();
});
