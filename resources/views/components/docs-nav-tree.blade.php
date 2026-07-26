@php
    /** @var list<array<string, mixed>> $nodes */
    $nodes ??= [];
    $depth ??= 0;
@endphp

<ul @class(['fi-pp-docs-tree', 'fi-pp-docs-tree--nested' => $depth > 0])>
    @foreach ($nodes as $node)
        @if (($node['type'] ?? null) === 'folder')
            @php
                $folderKey = (string) ($node['key'] ?? '');
                $isExpanded = in_array($folderKey, $this->expandedFolders, true);
                $children = $node['children'] ?? [];
            @endphp
            <li class="fi-pp-docs-tree__folder">
                <button
                    type="button"
                    class="fi-pp-docs-nav__folder"
                    wire:click="toggleFolder('{{ $folderKey }}')"
                    aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                >
                    <x-filament::icon
                        :icon="$isExpanded ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-right'"
                        class="fi-pp-docs-nav__chevron"
                    />
                    <x-filament::icon
                        :icon="$isExpanded ? 'heroicon-o-folder-open' : 'heroicon-o-folder'"
                        class="fi-pp-docs-nav__folder-icon"
                    />
                    <span>{{ $node['title'] ?? 'Folder' }}</span>
                </button>

                @if ($isExpanded && $children !== [])
                    @include('filament-project-passport::components.docs-nav-tree', [
                        'nodes' => $children,
                        'depth' => $depth + 1,
                    ])
                @endif
            </li>
        @elseif (($node['type'] ?? null) === 'file')
            <li class="fi-pp-docs-tree__file">
                <button
                    type="button"
                    wire:click="selectDocument('{{ $node['key'] }}')"
                    @class([
                        'fi-pp-docs-nav__btn',
                        'is-active' => $this->activeDocumentKey === ($node['key'] ?? null),
                    ])
                >
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="fi-pp-docs-nav__file-icon"
                    />
                    <span>{{ $node['title'] ?? 'Document' }}</span>
                </button>
            </li>
        @endif
    @endforeach
</ul>
