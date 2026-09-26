<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Recipe;
use App\Support\Links;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * "Links" section shown on pages, tasks/entries and recipes: what the node links to,
 * what links to it, and a search to link it with anything else.
 */
new class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public string $type = Links::ITEM;

    #[Locked]
    public int $nodeId;

    public string $query = '';

    public bool $adding = false;

    public function mount(string $type, int $id): void
    {
        $this->resolveHousehold();
        $this->type = $type === Links::RECIPE ? Links::RECIPE : Links::ITEM;
        $this->nodeId = $id;
        $this->assertVisible();
    }

    /**
     * The node itself must be visible to the user (it is also re-checked on every action).
     */
    private function assertVisible(): void
    {
        $visible = $this->type === Links::RECIPE
            ? Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->whereKey($this->nodeId)->exists()
            : Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())->whereKey($this->nodeId)->exists();

        abort_unless($visible, 404);
    }

    public function link(string $key): void
    {
        $this->assertVisible();
        [$toType, $toId] = Links::parseKey($key) ?? abort(422);
        abort_unless(Links::nodes([[$toType, $toId]], Auth::user(), (int) $this->householdId) !== [], 404);

        Links::addManual($this->type, $this->nodeId, $toType, $toId, (int) $this->householdId, Auth::id());
        $this->reset('query', 'adding');
    }

    public function unlink(string $key): void
    {
        $this->assertVisible();
        [$otherType, $otherId] = Links::parseKey($key) ?? abort(422);

        Links::removeManual($this->type, $this->nodeId, $otherType, $otherId);
    }

    public function with(): array
    {
        $overview = Links::overview($this->type, $this->nodeId, Auth::user(), (int) $this->householdId);
        $self = Links::key($this->type, $this->nodeId);

        return $overview + [
            'results' => $this->adding
                ? Links::search($this->query, Auth::user(), (int) $this->householdId, [$self, ...array_map(fn (array $node) => Links::key($node['type'], $node['id']), $overview['linked'])], 8)
                : [],
        ];
    }
}; ?>

<section class="space-y-3" aria-label="{{ __('Links') }}">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">🔗 {{ __('Links') }}</h2>
        <button type="button" wire:click="$toggle('adding')" class="text-sm text-indigo-600 dark:text-indigo-400 underline">+ {{ __('Link with …') }}</button>
    </div>

    @if ($adding)
        <div class="rounded-md bg-gray-50 dark:bg-gray-900/50 p-2">
            <x-text-input wire:model.live.debounce.250ms="query" type="search" class="block w-full text-sm" placeholder="{{ __('Search pages, tasks, entries, recipes …') }}" aria-label="{{ __('Search what to link') }}" autofocus />
            <ul class="mt-1 max-h-60 overflow-y-auto">
                @forelse ($results as $result)
                    <li wire:key="result-{{ $result['type'] }}-{{ $result['id'] }}">
                        <button type="button" wire:click="link(@js($result['type'].':'.$result['id']))" class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-start text-sm hover:bg-white dark:hover:bg-gray-800">
                            <span aria-hidden="true">{{ $result['icon'] }}</span>
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $result['title'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $result['kind'] }}</span>
                        </button>
                    </li>
                @empty
                    <li class="px-2 py-1.5 text-sm text-gray-500">{{ __('Nothing found.') }}</li>
                @endforelse
            </ul>
        </div>
    @endif

    @if ($linked !== [])
        <ul class="flex flex-wrap gap-2">
            @foreach ($linked as $node)
                <li wire:key="linked-{{ $node['type'] }}-{{ $node['id'] }}" class="inline-flex max-w-full items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/40 ps-2.5 pe-1.5 py-1 text-sm">
                    <a href="{{ $node['url'] }}" class="truncate text-indigo-800 dark:text-indigo-100 hover:underline" title="{{ $node['kind'] }}">{{ $node['icon'] }} {{ $node['title'] }}</a>
                    @if ($node['removable'])
                        <button type="button" wire:click="unlink(@js($node['type'].':'.$node['id']))" class="px-0.5 text-indigo-400 hover:text-red-600" aria-label="{{ __('Remove link') }}">✕</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <div>
        <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Linked from') }}</h3>
        @if ($linkedFrom !== [])
            <ul class="mt-1 space-y-1">
                @foreach ($linkedFrom as $node)
                    <li wire:key="from-{{ $node['type'] }}-{{ $node['id'] }}">
                        <a href="{{ $node['url'] }}" class="inline-flex items-center gap-2 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                            <span aria-hidden="true">{{ $node['icon'] }}</span> {{ $node['title'] }}
                            <span class="text-xs text-gray-500">· {{ $node['kind'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing links here yet.') }}</p>
        @endif
    </div>
</section>
