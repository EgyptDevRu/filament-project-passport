<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Support\DocumentationVisibility;
use EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class DocumentationPage extends Page
{
    use InteractsWithPassportNavigation;
    use LoadsPassportDataLazily;

    protected static ?string $slug = 'developer-support/documentation';

    /**
     * Menu visibility: hide only when the license is known unofficial.
     * Cold / failed / undefined status keeps the item visible so users are not
     * forced to open Status first. Markdown body stays gated separately.
     */
    public static function canAccess(): bool
    {
        if (! PageAuthorizer::canAccess()) {
            return false;
        }

        return ! app(LicenseApiClient::class)->isKnownUnofficialFromCache();
    }

    /**
     * Whether Markdown content may be listed/rendered in this environment.
     * Menu visibility is separate (license); this only gates page body content.
     */
    public function documentationContentAllowed(): bool
    {
        return DocumentationVisibility::contentAllowed();
    }

    /**
     * Official license from cache — never hits the network.
     */
    public function documentationLicenseAllowsContent(): bool
    {
        return app(LicenseApiClient::class)->allowsDocumentationFromCache();
    }

    /**
     * Still waiting on a background license check (nav visible, body locked).
     */
    public function documentationLicensePending(): bool
    {
        $cached = app(LicenseApiClient::class)->cachedPayload();

        return $cached === null || LicenseErrorCode::isUndefinedStatus($cached);
    }

    /**
     * True while a background license check has not produced a definitive result.
     * Used by Alpine to poll without calling PHP methods from the browser.
     */
    public bool $licensePending = true;

    /**
     * True when cached license is official and docs content may load.
     */
    public bool $licenseAllowsContent = false;

    /**
     * Public metadata only — never include absolute filesystem paths.
     *
     * @var list<array{key: string, title: string, relative: string, folder: string}>
     */
    #[Locked]
    public array $documents = [];

    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $documentTree = [];

    /**
     * @var list<string>
     */
    public array $expandedFolders = [];

    /**
     * Only ever set via selectDocument().
     */
    #[Locked]
    public ?string $activeDocumentKey = null;

    public function getView(): string
    {
        return 'filament-project-passport::pages.documentation';
    }

    protected static function passportPageKey(): string
    {
        return 'documentation';
    }

    protected static function passportDefaultLabel(): string
    {
        return 'Documentation';
    }

    protected static function passportDefaultIcon(): string
    {
        return 'heroicon-o-book-open';
    }

    protected static function passportDefaultSort(): int
    {
        return 2;
    }

    /**
     * HTML is derived server-side from the active key so clients cannot inject markup.
     */
    public function getActiveDocumentHtmlProperty(): string
    {
        if (! $this->documentationContentAllowed() || ! $this->documentationLicenseAllowsContent()) {
            return '';
        }

        if (! $this->ready || $this->activeDocumentKey === null || $this->activeDocumentKey === '') {
            return '';
        }

        if (! $this->isKnownDocumentKey($this->activeDocumentKey)) {
            return '';
        }

        return app(DocumentationScanner::class)->renderByKey($this->activeDocumentKey) ?? '';
    }

    public function selectDocument(string $key): void
    {
        if (! $this->documentationContentAllowed() || ! $this->documentationLicenseAllowsContent()) {
            return;
        }

        if (! $this->isKnownDocumentKey($key)) {
            return;
        }

        $this->activeDocumentKey = $key;
        $this->expandFoldersForKey($key);
    }

    public function toggleFolder(string $folderKey): void
    {
        if (! $this->documentationContentAllowed() || ! $this->documentationLicenseAllowsContent()) {
            return;
        }

        if (in_array($folderKey, $this->expandedFolders, true)) {
            $this->expandedFolders = array_values(array_filter(
                $this->expandedFolders,
                fn (string $key): bool => $key !== $folderKey
            ));

            return;
        }

        $this->expandedFolders[] = $folderKey;
    }

    /**
     * Kick a background license warm (never blocks nav) then load docs if allowed.
     */
    public function loadPageData(): void
    {
        if ($this->ready) {
            return;
        }

        app(LicenseApiClient::class)->dispatchBackgroundFetch();
        $this->syncLicenseFlags();

        if ($this->licenseAllowsContent && $this->documentationContentAllowed()) {
            $this->hydratePassportData();
        } else {
            $this->documents = [];
            $this->documentTree = [];
            $this->activeDocumentKey = null;
            $this->expandedFolders = [];
        }

        $this->ready = true;
    }

    /**
     * Poll while license is pending so docs appear once the background warm finishes.
     */
    public function pollLicenseStatus(): void
    {
        $client = app(LicenseApiClient::class);

        if ($client->cachedPayload() === null) {
            $client->dispatchBackgroundFetch();
        }

        $this->syncLicenseFlags();

        if ($client->isKnownUnofficialFromCache()) {
            $this->documents = [];
            $this->documentTree = [];
            $this->activeDocumentKey = null;

            return;
        }

        if ($this->licenseAllowsContent && $this->documentationContentAllowed() && $this->documents === []) {
            $this->hydratePassportData();
        }
    }

    private function syncLicenseFlags(): void
    {
        $client = app(LicenseApiClient::class);
        $cached = $client->cachedPayload();

        $this->licensePending = $cached === null || LicenseErrorCode::isUndefinedStatus($cached);
        $this->licenseAllowsContent = $client->allowsDocumentationFromCache();
    }

    protected function hydratePassportData(): void
    {
        if (! $this->documentationContentAllowed() || ! $this->documentationLicenseAllowsContent()) {
            $this->documents = [];
            $this->documentTree = [];
            $this->activeDocumentKey = null;
            $this->expandedFolders = [];

            return;
        }

        $scanner = app(DocumentationScanner::class);

        $this->documents = $scanner->listMarkdownFiles()
            ->map(fn (array $file): array => [
                'key' => $file['key'],
                'title' => $file['title'],
                'relative' => $file['relative'],
                'folder' => $file['folder'],
            ])
            ->all();
        $this->documentTree = $scanner->navigationTree();

        if ($this->documents === []) {
            $this->activeDocumentKey = null;
            $this->expandedFolders = [];

            return;
        }

        $first = $this->documents[0];
        $this->activeDocumentKey = $first['key'];
        $this->expandFoldersForKey($first['key']);
    }

    protected function isKnownDocumentKey(string $key): bool
    {
        foreach ($this->documents as $document) {
            if ($document['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    protected function expandFoldersForKey(string $documentKey): void
    {
        $document = collect($this->documents)->firstWhere('key', $documentKey);

        if ($document === null || $document['folder'] === '') {
            return;
        }

        $segments = explode('/', $document['folder']);
        $pathSoFar = [];

        foreach ($segments as $segment) {
            $pathSoFar[] = $segment;
            $folderKey = 'folder:'.implode('/', $pathSoFar);

            if (! in_array($folderKey, $this->expandedFolders, true)) {
                $this->expandedFolders[] = $folderKey;
            }
        }
    }
}
