<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode;
use EgyptDevRu\FilamentProjectPassport\Support\SupportCoverage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

class StatusPage extends Page
{
    use InteractsWithPassportNavigation;
    use LoadsPassportDataLazily;

    protected static ?string $slug = 'developer-support/status';

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $license = [];

    public function getView(): string
    {
        return 'filament-project-passport::pages.status';
    }

    protected static function passportPageKey(): string
    {
        return 'status';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'Status';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    protected static function passportDefaultSort(): int
    {
        return 1;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Support Status';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Support Status';
    }

    /**
     * Developer payload.
     *
     * @return array<string, mixed>
     */
    public function getDeveloperLog(): array
    {
        $client = app(LicenseApiClient::class);

        return [
            'opened_at' => now()->toIso8601String(),
            'application_host' => request()->getHost(),
            'cache_key' => $client->cacheKey(),
            'endpoint' => $client->endpoint(),
            'api_response' => $this->license,
        ];
    }

    /**
     * Bust cache and re-check the license API.
     */
    public function refreshLicenseCheck(): void
    {
        $this->license = app(LicenseApiClient::class)->refresh();
        $this->ready = true;

        Notification::make()
            ->title('License cache cleared')
            ->body('A fresh license check was performed for this domain.')
            ->success()
            ->send();
    }

    /**
     * Prefer cache; cold miss fetches in the background and polls (never blocks Filament nav).
     */
    public function loadPageData(): void
    {
        if ($this->ready) {
            return;
        }

        $client = app(LicenseApiClient::class);
        $cached = $client->cachedPayload();

        if ($cached !== null) {
            $this->license = $cached;
            $this->ready = true;

            return;
        }

        // Still try a short fetch on the Status page itself (user opened this page).
        // Navigation elsewhere must not hit the API — see DocumentationPage::canAccess().
        try {
            $this->license = $client->fetch();
        } catch (\Throwable $exception) {
            $this->license = [
                'is_official' => false,
                'developer_name' => null,
                'email' => null,
                'phone' => null,
                'support_warranty_until' => null,
                'extended_support_warranty_until' => null,
                'client_name' => null,
                'project_name' => null,
                'from_cache' => false,
                'request_failed' => true,
                'error_code' => LicenseErrorCode::UNKNOWN,
                'error' => LicenseErrorCode::description(LicenseErrorCode::UNKNOWN).': '.$exception->getMessage(),
                'http_status' => null,
            ];
        }

        $this->ready = true;
    }

    protected function hydratePassportData(): void
    {
        // Status overrides loadPageData() for cache-first behaviour.
    }

    /**
     * Domain registered with EgyptDev.
     */
    public function isOfficial(): bool
    {
        return ! $this->isUndefinedStatus()
            && SupportCoverage::isDomainVerified($this->license);
    }

    public function isUndefinedStatus(): bool
    {
        return LicenseErrorCode::isUndefinedStatus($this->license);
    }

    public function isDomainVerified(): bool
    {
        return $this->isOfficial();
    }

    public function isSupportActive(): bool
    {
        return SupportCoverage::isSupportActive($this->license);
    }

    public function isVerifiedWithoutActiveSupport(): bool
    {
        return SupportCoverage::isVerifiedWithoutActiveSupport($this->license);
    }

    public function requestFailed(): bool
    {
        return $this->isUndefinedStatus();
    }

    public function errorCode(): string
    {
        return (string) ($this->license['error_code'] ?? LicenseErrorCode::UNKNOWN);
    }
}
