<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

/**
 * Controls whether documentation Markdown content may be listed or rendered.
 *
 * The Documentation menu item can still appear (license-gated); this only
 * decides whether file contents are available — production by default so
 * staging/local installs cannot browse or copy shipped docs before go-live.
 */
final class DocumentationVisibility
{
    public static function contentAllowed(): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        return (bool) config('filament-project-passport.docs.allow_non_production', false);
    }
}
