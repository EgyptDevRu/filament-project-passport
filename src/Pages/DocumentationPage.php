<?php

namespace EgyptDevRu\FilamentProjectPassport\Pages;

use EgyptDevRu\FilamentProjectPassport\Pages\Concerns\InteractsWithPassportNavigation;
use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;
use EgyptDevRu\FilamentProjectPassport\Support\PageAuthorizer;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class DocumentationPage extends Page
{
    use InteractsWithPassportNavigation;

    protected static ?string $slug = 'developer-support/documentation';

    public static function canAccess(): bool
    {
        if (! PageAuthorizer::canAccess()) {
            return false;
        }

        // Hide docs for unofficial domains and undefined / failed license checks.
        return app(LicenseApiClient::class)->allowsDocumentation();
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

    public function mount(): void
    {
        $this->loadDocuments();
    }

    /**
     * HTML is derived server-side from the active key so clients cannot inject markup.
     */
    public function getActiveDocumentHtmlProperty(): string
    {
        if ($this->activeDocumentKey === null || $this->activeDocumentKey === '') {
            return '';
        }

        if (! $this->isKnownDocumentKey($this->activeDocumentKey)) {
            return '';
        }

        return app(DocumentationScanner::class)->renderByKey($this->activeDocumentKey) ?? '';
    }

    public function selectDocument(string $key): void
    {
        if (! $this->isKnownDocumentKey($key)) {
            return;
        }

        $this->activeDocumentKey = $key;
        $this->expandFoldersForKey($key);
    }

    public function toggleFolder(string $folderKey): void
    {
        if (in_array($folderKey, $this->expandedFolders, true)) {
            $this->expandedFolders = array_values(array_filter(
                $this->expandedFolders,
                fn (string $key): bool => $key !== $folderKey
            ));

            return;
        }

        $this->expandedFolders[] = $folderKey;
    }

    protected function loadDocuments(): void
    {
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
            if (($document['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    protected function expandFoldersForKey(string $documentKey): void
    {
        $document = collect($this->documents)->firstWhere('key', $documentKey);

        if ($document === null || ($document['folder'] ?? '') === '') {
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
