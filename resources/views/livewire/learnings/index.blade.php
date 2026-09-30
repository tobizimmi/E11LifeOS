<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Learning;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Lessons learned (second brain): write down what an experience, a mistake, a book or a person
 * taught you, and review it at growing intervals so it sticks.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** review or library */
    #[Url(except: 'review')]
    public string $tab = 'review';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $area = '';

    #[Url(except: '')]
    public string $kind = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** The review card shows its details after "Show". */
    public bool $revealed = false;

    /**
     * @var array{title: string, body: string, kind: string, area: string, source: string, shared: bool}
     */
    public array $learning = ['title' => '', 'body' => '', 'kind' => 'experience', 'area' => 'self', 'source' => '', 'shared' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->tab = in_array($this->tab, ['review', 'library'], true) ? $this->tab : 'review';
    }

    private function findLearning(int $id): Learning
    {
        return Learning::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'learning']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $learning = $this->findLearning($id);
        $this->editingId = $learning->id;
        $this->learning = ['title' => $learning->title, 'body' => (string) $learning->body, 'kind' => $learning->kind, 'area' => $learning->area, 'source' => (string) $learning->source, 'shared' => ! $learning->isPrivate()];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'learning.title' => ['required', 'string', 'max:300'],
            'learning.body' => ['nullable', 'string', 'max:5000'],
            'learning.kind' => ['required', Rule::in(array_keys(Learning::kinds()))],
            'learning.area' => ['required', Rule::in(array_keys(Learning::areas()))],
            'learning.source' => ['nullable', 'string', 'max:255'],
        ], attributes: ['learning.title' => __('What did you learn?')])['learning'];

        $attributes = [
            'title' => $data['title'],
            'body' => trim((string) $data['body']) ?: null,
            'kind' => $data['kind'],
            'area' => $data['area'],
            'source' => trim((string) $data['source']) ?: null,
            'visibility' => $this->learning['shared'] ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE,
        ];

        if ($this->editingId) {
            $learning = $this->findLearning($this->editingId);
            $learning->update($learning->created_by === Auth::id() ? $attributes : array_diff_key($attributes, ['visibility' => true]));
        } else {
            // The first review comes the next day.
            Learning::create($attributes + ['household_id' => $this->householdId, 'created_by' => Auth::id(), 'interval_days' => 1, 'due_on' => today()->addDay()]);
            $this->dispatch('toast', message: __('Saved – it will come back for review tomorrow.'));
        }

        $this->reset(['editingId', 'learning', 'showForm']);
    }

    public function review(int $id, string $rating): void
    {
        abort_unless(in_array($rating, ['again', 'good', 'easy'], true), 422);
        $this->findLearning($id)->review($rating);
        $this->revealed = false;
    }

    public function markApplied(int $id): void
    {
        $learning = $this->findLearning($id);
        $learning->update(['applied_count' => $learning->applied_count + 1]);
        $this->dispatch('toast', message: __('Nice – you applied it.'));
    }

    public function toggleArchive(int $id): void
    {
        $learning = $this->findLearning($id);
        $learning->update(['archived_at' => $learning->archived_at ? null : now()]);
    }

    public function delete(int $id): void
    {
        $this->findLearning($id)->delete();
        $this->reset(['editingId', 'learning', 'showForm']);
    }

    public function with(): array
    {
        $visible = fn () => Learning::query()->visibleTo(Auth::user(), (int) $this->householdId);
        $due = $visible()->whereNull('archived_at')->whereDate('due_on', '<=', today())->orderBy('due_on')->orderBy('id')->get();
        $all = $visible()->latest()->get();
        $term = mb_strtolower(trim($this->search));

        return [
            'due' => $due,
            'current' => $due->first(),
            'library' => $all
                ->when($this->area !== '', fn ($items) => $items->where('area', $this->area))
                ->when($this->kind !== '', fn ($items) => $items->where('kind', $this->kind))
                // Encrypted texts are searched after decrypting.
                ->when($term !== '', fn ($items) => $items->filter(fn (Learning $learning) => str_contains(mb_strtolower($learning->title.' '.$learning->body.' '.$learning->source), $term)))
                ->values(),
            'yearAgo' => $all->filter(fn (Learning $learning) => $learning->created_at?->isSameDay(today()->subYear()) || $learning->created_at?->between(today()->subYear()->subDays(3), today()->subYear()->addDays(3)))->values(),
            'total' => $all->count(),
            'mastered' => $all->where('interval_days', '>=', 60)->count(),
            'nextDue' => $visible()->whereNull('archived_at')->whereDate('due_on', '>', today())->min('due_on'),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $kinds = \App\Models\Learning::kinds();
    $areas = \App\Models\Learning::areas();
    $chip = fn (bool $active) => $active ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Lessons learned') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-2">
                <nav class="flex flex-1 gap-1 rounded-xl bg-gray-100 p-1 text-sm dark:bg-gray-800" aria-label="{{ __('Sections') }}">
                    @foreach (['review' => '🔁 '.__('Review').($due->count() ? ' ('.$due->count().')' : ''), 'library' => '📚 '.__('All lessons').' ('.$total.')'] as $key => $label)
                        <button type="button" wire:click="$set('tab', '{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" @class(['flex-1 rounded-lg px-3 py-2 font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </nav>
                <x-primary-button type="button" wire:click="create">+ {{ __('Lesson') }}</x-primary-button>
            </div>

            @if ($showForm)
                <form wire:submit="save" @if (! $editingId) data-draft="learning" data-draft-restored="{{ __('Draft restored') }}" @endif class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('What did you learn?') }}<input type="text" wire:model="learning.title" class="{{ $input }}" placeholder="{{ __('e.g. Never sign a contract on the same day') }}" autofocus><x-input-error :messages="$errors->get('learning.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('What happened, and what will you do differently?') }}<textarea wire:model="learning.body" rows="4" class="{{ $input }}" placeholder="{{ __('Situation – what I did – what came of it – what I take away') }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                        <select wire:model="learning.kind" class="{{ $input }}">
                            @foreach ($kinds as $key => $option)
                                <option value="{{ $key }}">{{ $option['icon'] }} {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Area of life') }}
                        <select wire:model="learning.area" class="{{ $input }}">
                            @foreach ($areas as $key => $option)
                                <option value="{{ $key }}">{{ $option['icon'] }} {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Source') }}<input type="text" wire:model="learning.source" class="{{ $input }}" placeholder="{{ __('e.g. book title, person, situation') }}"></label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="learning.shared" class="rounded border-gray-300 text-indigo-600"> {{ __('Share with the household') }}</label>
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingId ? __('Save changes') : __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this lesson?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>
            @endif

            @if ($tab === 'review')
                @if ($current)
                    <section class="rounded-2xl bg-white p-6 shadow-sm dark:bg-gray-800" aria-labelledby="review-card" wire:key="review-{{ $current->id }}-{{ $current->review_count }}">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $kinds[$current->kind]['icon'] ?? '' }} {{ $kinds[$current->kind]['label'] ?? '' }} · {{ $areas[$current->area]['icon'] ?? '' }} {{ $areas[$current->area]['label'] ?? '' }} · {{ trans_choice(':count lesson due|:count lessons due', $due->count()) }}</p>
                        <h3 id="review-card" class="mt-2 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $current->title }}</h3>
                        @if ($revealed)
                            @if ($current->body)
                                <p class="mt-3 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $current->body }}</p>
                            @endif
                            @if ($current->source)
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">— {{ $current->source }}</p>
                            @endif
                            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('How well did you remember it – and did you apply it?') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" wire:click="review({{ $current->id }}, 'again')" class="rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-100">↺ {{ __('Again tomorrow') }}</button>
                                <button type="button" wire:click="review({{ $current->id }}, 'good')" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-700">✓ {{ __('Remembered') }}</button>
                                <button type="button" wire:click="review({{ $current->id }}, 'easy')" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700">★ {{ __('Easy – second nature') }}</button>
                                <button type="button" wire:click="markApplied({{ $current->id }})" class="ms-auto rounded-lg px-3 py-2 text-sm ring-1 ring-gray-300 dark:ring-gray-600">💡 {{ __('Applied it') }} @if ($current->applied_count) ({{ $current->applied_count }}) @endif</button>
                            </div>
                        @else
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('First remember what was behind it, then show the details.') }}</p>
                            <button type="button" wire:click="$set('revealed', true)" class="mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('Show') }}</button>
                        @endif
                    </section>
                @else
                    <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-4xl" aria-hidden="true">{{ $total ? '✅' : '🎓' }}</p>
                        @if ($total)
                            <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('Nothing to review today.') }}</p>
                            @if ($nextDue)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Next review on :date.', ['date' => \Illuminate\Support\Carbon::parse($nextDue)->format('d.m.Y')]) }}</p>
                            @endif
                        @else
                            <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No lessons yet.') }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Write down what an experience, a mistake, a book or a conversation taught you. The app brings it back after 1, 3, 7, 16 … days – that is how lessons stick.') }}</p>
                        @endif
                    </section>
                @endif

                <section class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-2xl bg-white p-3 shadow-sm dark:bg-gray-800"><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $total }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Lessons') }}</p></div>
                    <div class="rounded-2xl bg-white p-3 shadow-sm dark:bg-gray-800"><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $due->count() }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Due today') }}</p></div>
                    <div class="rounded-2xl bg-white p-3 shadow-sm dark:bg-gray-800"><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $mastered }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Second nature') }}</p></div>
                </section>

                @if ($yearAgo->isNotEmpty())
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🕰️ {{ __('One year ago you learned') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($yearAgo as $item)
                                <li wire:key="year-{{ $item->id }}" class="text-gray-700 dark:text-gray-300">{{ $kinds[$item->kind]['icon'] ?? '' }} {{ $item->title }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endif

            @if ($tab === 'library')
                <div class="space-y-2 rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search lessons …') }}" aria-label="{{ __('Search lessons') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        @foreach ($areas as $key => $option)
                            <button type="button" wire:click="$set('area', '{{ $area === $key ? '' : $key }}')" aria-pressed="{{ $area === $key ? 'true' : 'false' }}" class="rounded-full px-2.5 py-0.5 {{ $chip($area === $key) }}">{{ $option['icon'] }} {{ $option['label'] }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        @foreach ($kinds as $key => $option)
                            <button type="button" wire:click="$set('kind', '{{ $kind === $key ? '' : $key }}')" aria-pressed="{{ $kind === $key ? 'true' : 'false' }}" class="rounded-full px-2.5 py-0.5 {{ $chip($kind === $key) }}">{{ $option['icon'] }} {{ $option['label'] }}</button>
                        @endforeach
                    </div>
                </div>

                <ul class="space-y-3">
                    @forelse ($library as $item)
                        <li wire:key="lesson-{{ $item->id }}" @class(['rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800', 'opacity-60' => $item->archived_at])>
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $kinds[$item->kind]['icon'] ?? '' }} {{ $item->title }}
                                    @unless ($item->isPrivate())
                                        <span class="text-xs font-normal text-gray-500">· {{ __('shared') }}</span>
                                    @endunless
                                </p>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $areas[$item->area]['icon'] ?? '' }} {{ $areas[$item->area]['label'] ?? '' }}</span>
                            </div>
                            @if ($item->body)
                                <p class="mt-1 line-clamp-3 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $item->body }}</p>
                            @endif
                            <p class="mt-2 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                @if ($item->source)<span>— {{ $item->source }}</span>@endif
                                <span>{{ $item->created_at?->format('d.m.Y') }}</span>
                                @if ($item->archived_at)
                                    <span>{{ __('archived') }}</span>
                                @elseif ($item->due_on)
                                    <span>🔁 {{ __('next review :date', ['date' => $item->due_on->format('d.m.Y')]) }}</span>
                                @endif
                                @if ($item->applied_count)<span>💡 {{ trans_choice('applied :count time|applied :count times', $item->applied_count) }}</span>@endif
                            </p>
                            <div class="mt-2 flex gap-3 text-xs">
                                <button type="button" wire:click="edit({{ $item->id }})" class="text-gray-600 underline dark:text-gray-300">{{ __('Edit') }}</button>
                                <button type="button" wire:click="toggleArchive({{ $item->id }})" class="text-gray-600 underline dark:text-gray-300">{{ $item->archived_at ? __('Review again') : __('Stop reviewing') }}</button>
                            </div>
                        </li>
                    @empty
                        <li class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ $total ? __('No lesson matches the filter.') : __('No lessons yet.') }}</li>
                    @endforelse
                </ul>
            @endif
        </div>
    </div>
</div>
