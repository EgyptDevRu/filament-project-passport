<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages\Concerns;

use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Htmlable;

trait InteractsWithPassportNavigation
{
    abstract protected static function passportPageKey(): string;

    public static function canAccess(): bool
    {
        return PageAuthorizer::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        $key = static::passportPageKey();

        return (string) config(
            "filament-project-passport.navigation.pages.{$key}.label",
            static::passportDefaultLabel()
        );
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('filament-project-passport.navigation.group', 'Developer Support');

        return is_string($group) && $group !== '' ? $group : 'Developer Support';
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::canAccess()) {
            return [];
        }

        $key = static::passportPageKey();
        $icon = config(
            "filament-project-passport.navigation.pages.{$key}.icon",
            static::passportDefaultIcon()
        );
        $sort = config(
            "filament-project-passport.navigation.pages.{$key}.sort",
            static::passportDefaultSort()
        );
        $groupSort = (int) config('filament-project-passport.navigation.group_sort', 9990);

        // Keep Developer Support pages near the end; page sort is offset by the group sort.
        $resolvedSort = $groupSort + (is_numeric($sort) ? (int) $sort : static::passportDefaultSort());

        $item = NavigationItem::make(static::getNavigationLabel())
            ->icon(is_string($icon) ? $icon : static::passportDefaultIcon())
            ->group(static::getNavigationGroup())
            ->sort($resolvedSort)
            ->url(static::getUrl())
            ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteName()))
            ->key(static::class);

        return [$item];
    }

    public function getTitle(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    public function getHeading(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    abstract protected static function passportDefaultLabel(): string;

    abstract protected static function passportDefaultIcon(): string;

    abstract protected static function passportDefaultSort(): int;
}
