<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\LoadsPassportDataLazily;
use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Support\DocumentationVisibility;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class DocumentationPage extends Page
{
    use InteractsWithPassportNavigation;
    use LoadsPassportDataLazily;

    protected static ?string $slug = 'developer-support/documentation';

    public static function canAccess(): bool
    {
        if (! PageAuthorizer::canAccess()) {
            return false;
        }

        // Cache only — never call the license API while Filament builds navigation
        // (runs on every admin page and was causing 20–50s "waiting for server").
        return app(LicenseApiClient::class)->allowsDocumentationFromCache();
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
        if (! $this->documentationContentAllowed()) {
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
        if (! $this->documentationContentAllowed()) {
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
        if (! $this->documentationContentAllowed()) {
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

    protected function hydratePassportData(): void
    {
        if (! $this->documentationContentAllowed()) {
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
