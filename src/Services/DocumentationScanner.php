<?php

namespace EgyptDevRu\FilamentProjectPassport\Services;

use DOMDocument;
use DOMElement;
use EgyptDevRu\FilamentProjectPassport\Support\DocumentationVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Finder\SplFileInfo;

final class DocumentationScanner
{
    private ?MarkdownConverter $converter = null;

    /**
     * Flat list of every Markdown file under `.docs` (recursive).
     *
     * @return Collection<int, array{key: string, title: string, path: string, relative: string, folder: string}>
     */
    public function listMarkdownFiles(): Collection
    {
        $directory = $this->docsPath();

        if (! $this->isEnabled() || ! DocumentationVisibility::contentAllowed() || ! File::isDirectory($directory)) {
            return collect();
        }

        return collect(File::allFiles($directory))
            ->filter(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'md')
            ->sortBy(fn (SplFileInfo $file): string => strtolower(str_replace('\\', '/', $file->getRelativePathname())))
            ->values()
            ->map(function (SplFileInfo $file): array {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $folder = str_contains($relative, '/')
                    ? Str::beforeLast($relative, '/')
                    : '';

                return [
                    'key' => md5($relative),
                    'title' => $this->titleFromFilename(pathinfo($relative, PATHINFO_FILENAME)),
                    'path' => $file->getPathname(),
                    'relative' => $relative,
                    'folder' => $folder,
                ];
            });
    }

    /**
     * Nested navigation tree: folders (with children) and files.
     *
     * @return list<array{
     *     type: 'folder'|'file',
     *     key: string,
     *     title: string,
     *     relative?: string,
     *     children?: list<array<string, mixed>>
     * }>
     */
    public function navigationTree(): array
    {
        $files = $this->listMarkdownFiles();

        if ($files->isEmpty()) {
            return [];
        }

        /** @var array<string, mixed> $root */
        $root = [
            'type' => 'folder',
            'key' => 'root',
            'title' => 'Documentation',
            'children' => [],
        ];

        foreach ($files as $file) {
            $segments = $file['folder'] === ''
                ? []
                : explode('/', $file['folder']);

            $cursor = &$root;

            $pathSoFar = [];

            foreach ($segments as $segment) {
                $pathSoFar[] = $segment;
                $folderKey = 'folder:'.implode('/', $pathSoFar);

                $existingIndex = null;

                foreach ($cursor['children'] as $index => $child) {
                    if (($child['type'] ?? null) === 'folder' && ($child['key'] ?? null) === $folderKey) {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex === null) {
                    $cursor['children'][] = [
                        'type' => 'folder',
                        'key' => $folderKey,
                        'title' => $this->titleFromFilename($segment),
                        'relative' => implode('/', $pathSoFar),
                        'children' => [],
                    ];
                    $existingIndex = array_key_last($cursor['children']);
                }

                $cursor = &$cursor['children'][$existingIndex];
            }

            $cursor['children'][] = [
                'type' => 'file',
                'key' => $file['key'],
                'title' => $file['title'],
                'relative' => $file['relative'],
            ];

            unset($cursor);
        }

        return $root['children'];
    }

    public function renderByKey(string $key): ?string
    {
        $file = $this->listMarkdownFiles()->firstWhere('key', $key);

        if ($file === null) {
            return null;
        }

        return $this->renderFile($file['path'], $file['relative']);
    }

    public function renderFile(string $absolutePath, ?string $currentRelative = null): string
    {
        if (! DocumentationVisibility::contentAllowed()) {
            return '';
        }

        if (! File::isFile($absolutePath) || ! Str::endsWith(strtolower($absolutePath), '.md')) {
            return '';
        }

        $realDocs = realpath($this->docsPath());
        $realFile = realpath($absolutePath);

        if ($realDocs === false || $realFile === false || ! $this->isPathInsideDirectory($realFile, $realDocs)) {
            return '';
        }

        if ($currentRelative === null) {
            $currentRelative = str_replace('\\', '/', substr($realFile, strlen($realDocs) + 1));
        }

        $markdown = File::get($realFile);
        $html = $this->converter()->convert($markdown)->getContent();

        return $this->enhanceRenderedHtml($html, $currentRelative);
    }

    public function docsPath(): string
    {
        $relative = (string) config('filament-project-passport.docs.directory', '.docs');
        $relative = str_replace(['\\', "\0"], ['/', ''], $relative);
        $relative = ltrim($relative, '/');

        // Keep docs under the application root even if config is mis-set.
        $normalized = $this->normalizeRelativePath($relative);

        if ($normalized === '' || str_contains($normalized, ':')) {
            $normalized = '.docs';
        }

        return base_path($normalized);
    }

    public function isEnabled(): bool
    {
        return (bool) config('filament-project-passport.docs.enabled', true);
    }

    public function hasDocumentation(): bool
    {
        return $this->listMarkdownFiles()->isNotEmpty();
    }

    /**
     * Resolve a markdown href against the current file into a docs-relative path.
     */
    public function resolveMarkdownHref(string $currentRelative, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('#^(https?:|mailto:|tel:)#i', $href) === 1) {
            return null;
        }

        $path = str_replace('\\', '/', $href);
        $path = strtok($path, '?#') ?: $path;
        $path = rawurldecode($path);

        $baseDir = str_contains($currentRelative, '/')
            ? Str::beforeLast($currentRelative, '/')
            : '';

        if (str_starts_with($path, '/')) {
            $resolved = ltrim($path, '/');
        } else {
            $resolved = $baseDir === '' ? $path : $baseDir.'/'.$path;
        }

        $resolved = $this->normalizeRelativePath($resolved);

        if ($resolved === '') {
            return null;
        }

        $known = $this->listMarkdownFiles()->pluck('relative')->all();

        if (in_array($resolved, $known, true)) {
            return $resolved;
        }

        if (! str_ends_with(strtolower($resolved), '.md') && in_array($resolved.'.md', $known, true)) {
            return $resolved.'.md';
        }

        return null;
    }

    private function enhanceRenderedHtml(string $html, string $currentRelative): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $knownByRelative = $this->listMarkdownFiles()
            ->mapWithKeys(fn (array $file): array => [$file['relative'] => $file['key']])
            ->all();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="fi-pp-md-root">'.$html.'</div>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('fi-pp-md-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $this->rewriteDocumentationLinks($root, $dom, $knownByRelative, $currentRelative);
        $this->transformMermaidBlocks($root, $dom);

        $inner = '';

        foreach ($root->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return $inner;
    }

    /**
     * @param  array<string, string>  $knownByRelative
     */
    private function rewriteDocumentationLinks(
        DOMElement $root,
        DOMDocument $dom,
        array $knownByRelative,
        string $currentRelative,
    ): void {
        // Snapshot first — the live NodeList mutates when we replace anchors.
        $anchors = [];

        foreach ($root->getElementsByTagName('a') as $node) {
            $anchors[] = $node;
        }

        foreach ($anchors as $node) {
            /** @var DOMElement $anchor */
            $anchor = $node;
            $href = $anchor->getAttribute('href');

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            if (preg_match('#^(https?:|mailto:|tel:)#i', $href) === 1) {
                if (preg_match('#^https?:#i', $href) === 1) {
                    $anchor->setAttribute('target', '_blank');
                    $anchor->setAttribute('rel', 'noopener noreferrer');
                }

                continue;
            }

            $resolved = $this->resolveMarkdownHref($currentRelative, $href);

            if ($resolved !== null && isset($knownByRelative[$resolved])) {
                $anchor->setAttribute('href', '#');
                $anchor->setAttribute('class', trim($anchor->getAttribute('class').' fi-pp-doc-link'));
                $anchor->setAttribute('data-doc-key', $knownByRelative[$resolved]);
                $anchor->setAttribute('role', 'button');

                continue;
            }

            // Non-resolvable relative / internal links: keep text, remove navigation.
            $span = $dom->createElement('span');
            $span->setAttribute('class', 'fi-pp-doc-link-disabled');

            while ($anchor->firstChild) {
                $span->appendChild($anchor->firstChild);
            }

            $anchor->parentNode?->replaceChild($span, $anchor);
        }
    }

    private function transformMermaidBlocks(DOMElement $root, DOMDocument $dom): void
    {
        // Snapshot nodes first — the live NodeList mutates while we replace <pre> tags.
        $pres = [];

        foreach ($root->getElementsByTagName('pre') as $node) {
            $pres[] = $node;
        }

        foreach ($pres as $node) {
            /** @var DOMElement $pre */
            $pre = $node;
            $code = null;

            foreach ($pre->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'code') {
                    $code = $child;
                    break;
                }
            }

            if (! $code instanceof DOMElement) {
                continue;
            }

            $source = $code->textContent ?? '';

            if (! $this->isMermaidSource($code->getAttribute('class'), $source)) {
                continue;
            }

            $div = $dom->createElement('div');
            $div->setAttribute('class', 'fi-pp-mermaid mermaid');
            $div->appendChild($dom->createTextNode($source));
            $pre->parentNode?->replaceChild($div, $pre);
        }
    }

    private function isMermaidSource(string $class, string $source): bool
    {
        if (str_contains($class, 'language-mermaid')) {
            return true;
        }

        $trimmed = ltrim($source);

        return preg_match(
            '/^(sequenceDiagram|flowchart|graph\s|classDiagram|stateDiagram(?:-v2)?|erDiagram|gantt|pie(?:\s|$)|journey|gitGraph|mindmap|timeline|quadrantChart|C4Context|requirementDiagram|sankey-beta|xychart-beta|block-beta)\b/m',
            $trimmed
        ) === 1;
    }

    /**
     * Prevent prefix bypasses such as `.docs` matching `.docs-evil`.
     */
    private function isPathInsideDirectory(string $realFile, string $realDirectory): bool
    {
        $directory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realDirectory), DIRECTORY_SEPARATOR);
        $file = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realFile);

        if ($file === $directory) {
            return true;
        }

        $prefix = $directory.DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            return str_starts_with(strtolower($file), strtolower($prefix));
        }

        return str_starts_with($file, $prefix);
    }

    private function normalizeRelativePath(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function titleFromFilename(string $name): string
    {
        // Keep short acronym-style folder/file names (API, FAQ, CMS).
        if (preg_match('/^[A-Z0-9]{2,8}$/', $name) === 1) {
            return $name;
        }

        return Str::of($name)
            ->replace(['-', '_'], ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->title()
            ->toString();
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter instanceof MarkdownConverter) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return $this->converter = new MarkdownConverter($environment);
    }
}
