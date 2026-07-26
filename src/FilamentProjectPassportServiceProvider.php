<?php

namespace EgyptDevRu\FilamentProjectPassport;

use EgyptDevRu\FilamentProjectPassport\Commands\RefreshDependencyAuditCommand;
use EgyptDevRu\FilamentProjectPassport\Commands\RefreshLicenseAuditCommand;
use EgyptDevRu\FilamentProjectPassport\Pages\DependencyAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\DocumentationPage;
use EgyptDevRu\FilamentProjectPassport\Pages\LicenseAuditPage;
use EgyptDevRu\FilamentProjectPassport\Pages\StatusPage;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerDependencyAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\ComposerLicenseAuditor;
use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentProjectPassportServiceProvider extends PackageServiceProvider
{
    /**
     * @var list<class-string>
     */
    public const array PAGES = [
        StatusPage::class,
        DocumentationPage::class,
        LicenseAuditPage::class,
        DependencyAuditPage::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-project-passport')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                RefreshDependencyAuditCommand::class,
                RefreshLicenseAuditCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(LicenseApiClient::class);
        $this->app->singleton(DocumentationScanner::class);
        $this->app->singleton(ComposerLicenseAuditor::class);
        $this->app->singleton(ComposerDependencyAuditor::class);

        if (class_exists(Panel::class)) {
            Panel::configureUsing(function (Panel $panel): void {
                $panel->pages(self::PAGES);
            });
        }

        if (class_exists(PanelRegistry::class)) {
            $this->app->afterResolving(PanelRegistry::class, function (PanelRegistry $registry): void {
                // PanelRegistry is an internal-ish Filament class whose API
                // has shifted across major versions.
                if (! method_exists($registry, 'all')) {
                    return;
                }

                foreach ($registry->all() as $panel) {
                    $this->ensurePagesRegistered($panel);
                }
            });
        }
    }

    public function packageBooted(): void
    {
        $this->registerStyles();
        $this->registerEnvironmentBadge();
        $this->registerSchedule();

        if (! class_exists(Filament::class) || ! $this->app->bound('filament')) {
            return;
        }

        Filament::serving(function (): void {
            foreach (Filament::getPanels() as $panel) {
                $this->ensurePagesRegistered($panel);
            }
        });
    }

    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Daily at 03:00: Sunday always refreshes; other days skip when cache is fresh.
            $schedule
                ->command('filament-project-passport:refresh-dependency-audit')
                ->dailyAt('03:00')
                ->name('filament-project-passport-dependency-audit')
                ->withoutOverlapping();

            // Runs daily at 03:00; command skips when the 14-day cache is still fresh.
            $schedule
                ->command('filament-project-passport:refresh-license-audit')
                ->dailyAt('03:00')
                ->name('filament-project-passport-license-audit')
                ->withoutOverlapping();
        });
    }

    protected function registerEnvironmentBadge(): void
    {
        if (! class_exists(FilamentView::class) || ! class_exists(PanelsRenderHook::class)) {
            return;
        }

        $render = fn (): string => view('filament-project-passport::components.environment-badge')->render();

        // Guard each constant individually — class_exists() only proves the
        // class survived, not that every hook name it defines still exists
        // (render hooks have been added/renamed across Filament majors).
        if (defined(PanelsRenderHook::class.'::TOPBAR_LOGO_AFTER')) {
            // Desktop: logo lives in the topbar (fi-topbar-start is lg:flex only).
            FilamentView::registerRenderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, $render);
        }

        if (defined(PanelsRenderHook::class.'::SIDEBAR_LOGO_AFTER')) {
            // Mobile: logo lives in the sidebar header (fi-sidebar-header is lg:hidden with topbar).
            FilamentView::registerRenderHook(PanelsRenderHook::SIDEBAR_LOGO_AFTER, $render);
        }
    }

    protected function registerStyles(): void
    {
        $stylesheet = dirname(__DIR__).'/resources/dist/filament-project-passport.css';

        if (! is_file($stylesheet)) {
            return;
        }

        /*
         * Inline CSS via render hook — avoids FilamentAsset publish URLs
         * (e.g. /css/egyptdevru/.../filament-project-passport.css) that 404
         * unless `php artisan filament:assets` is run in the host app.
         */
        if (
            class_exists(FilamentView::class)
            && class_exists(PanelsRenderHook::class)
            && defined(PanelsRenderHook::class.'::STYLES_AFTER')
        ) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::STYLES_AFTER,
                function () use ($stylesheet): string {
                    $css = file_get_contents($stylesheet);

                    if ($css === false || $css === '') {
                        return '';
                    }

                    return '<style data-filament-project-passport="1">'.$css.'</style>';
                },
            );
        }
    }

    protected function ensurePagesRegistered(Panel $panel): void
    {
        $pages = $panel->getPages();
        $missing = [];

        foreach (self::PAGES as $page) {
            if (
                in_array($page, $pages, true)
                || array_key_exists($page, $pages)
            ) {
                continue;
            }

            $missing[] = $page;
        }

        if ($missing !== []) {
            $panel->pages($missing);
        }
    }
}
