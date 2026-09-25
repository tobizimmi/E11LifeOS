@props(['tree', 'pages', 'currentId' => null, 'openIds' => [], 'depth' => 0, 'privateSection' => false])

{{-- Recursive page tree for the sidebar. $tree is an App\Support\PageTree, $pages one level of it. --}}
<ul @class(['space-y-0.5', 'ms-3 border-s border-gray-200 dark:border-gray-700 ps-1' => $depth > 0])>
    @foreach ($pages as $page)
        @php($children = $tree->children($page->id))
        <li wire:key="tree-{{ $page->id }}" x-data="{ open: @js(in_array($page->id, $openIds, true)) }">
            <div @class([
                'group flex items-center gap-1 rounded-md pe-1 text-sm',
                'bg-indigo-50 text-indigo-700 font-medium dark:bg-gray-700 dark:text-white' => $page->id === $currentId,
                'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700/60' => $page->id !== $currentId,
            ])>
                @if ($children !== [])
                    <button type="button" x-on:click="open = ! open" class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" :aria-expanded="open" aria-label="{{ __('Show subpages') }}">
                        <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                    </button>
                @else
                    <span class="w-5 shrink-0"></span>
                @endif

                <a href="{{ route('pages.index', ['item' => $page->id]) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-1.5 py-1" @if ($page->id === $currentId) aria-current="page" @endif>
                    <span aria-hidden="true">{{ $page->displayIcon() }}</span>
                    <span class="truncate">{{ $page->title }}</span>
                    @if ($page->isPrivate() && ! $privateSection)
                        <span class="sr-only">({{ __('Private') }})</span>
                        <svg class="h-3 w-3 shrink-0 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
                        </svg>
                    @endif
                </a>

                <a href="{{ route('pages.index', ['create' => 1, 'parent' => $page->id]) }}" wire:navigate class="px-1 lg:hidden lg:group-hover:block lg:group-focus-within:block text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Add subpage') }}">
                    <span aria-hidden="true">+</span><span class="sr-only">{{ __('Add subpage') }}</span>
                </a>
            </div>

            @if ($children !== [])
                <div x-show="open" x-cloak>
                    <x-page-tree :tree="$tree" :pages="$children" :current-id="$currentId" :open-ids="$openIds" :depth="$depth + 1" :private-section="$privateSection" />
                </div>
            @endif
        </li>
    @endforeach
</ul>
