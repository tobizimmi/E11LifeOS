<?php

use App\Models\Household;
use App\Models\ModuleEntitlement;
use App\Models\User;
use App\Support\Admin\ModuleRights;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Admin: release or lock every area for one household or one account, optionally until a date.
 * Licenses and trials (later) are shown next to it and stay untouched.
 */
new class extends Component
{
    /** household or user */
    #[Locked]
    public string $subjectType = 'household';

    #[Locked]
    public int $subjectId = 0;

    /** @var array<string, string> module => date (Y-m-d) */
    public array $until = [];

    public function mount(): void
    {
        $this->authorize('admin');
        abort_unless(in_array($this->subjectType, ['household', 'user'], true), 404);

        foreach (ModuleRights::entries($this->subjectType, $this->subjectId) as $module => $entry) {
            $this->until[$module] = $entry['admin']?->ends_at?->toDateString() ?? '';
        }
    }

    public function setAccess(string $module, string $access): void
    {
        $this->authorize('admin');
        $this->validate(['until.'.$module => ['nullable', 'date', 'after_or_equal:today']], attributes: ['until.'.$module => __('until')]);
        $until = filled($this->until[$module] ?? null) ? Carbon::parse($this->until[$module]) : null;
        ModuleRights::set(Auth::user(), $this->subjectType, $this->subjectId, $module, $access, $access === ModuleRights::DEFAULT ? null : $until);

        if ($access === ModuleRights::DEFAULT) {
            $this->until[$module] = '';
        }

        $this->dispatch('toast', message: __('Saved.'));
    }

    public function with(): array
    {
        $user = $this->subjectType === 'user' ? User::findOrFail($this->subjectId) : null;
        $household = $this->subjectType === 'household' ? Household::findOrFail($this->subjectId) : $user?->currentHousehold();

        return [
            'modules' => ModuleCatalog::all(),
            'entries' => ModuleRights::entries($this->subjectType, $this->subjectId),
            'effective' => collect(ModuleCatalog::all())->map(fn (array $module, string $key) => $user
                ? ModuleAccess::explain($user, $key)
                : ['allowed' => ModuleAccess::availableForHousehold($household, $key), 'reason' => ''])->all(),
        ];
    }
}; ?>

<div class="space-y-2">
    <p class="text-xs text-gray-500 dark:text-gray-400">
        {{ $subjectType === 'household' ? __('Applies to every member of the household. A lock for a single account always wins.') : __('Applies to this account only, in addition to the release of the household. A lock always wins.') }}
    </p>
    <ul class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
        @foreach ($modules as $key => $module)
            @php($entry = $entries[$key])
            @php($current = $entry['admin']?->access ?? 'default')
            <li wire:key="module-{{ $subjectType }}-{{ $subjectId }}-{{ $key }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                <span class="min-w-0 flex-1 text-sm text-gray-900 hyphens-auto dark:text-gray-100">
                    {{ $module['icon'] }} {{ $module['label'] }}
                    @if (\App\Support\Modules\ModuleAccess::plan($key) === \App\Support\Modules\ModuleAccess::LICENSED)
                        <span class="ms-1 whitespace-nowrap rounded-sm bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ __('Licensed') }}</span>
                    @endif
                    @foreach ($entry['others'] as $other)
                        <span class="ms-1 whitespace-nowrap rounded-sm bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{{ $other->source === 'trial' ? __('Trial') : __('License') }}@if ($other->ends_at) {{ __('until :date', ['date' => $other->ends_at->format('d.m.Y')]) }}@endif</span>
                    @endforeach
                    <span @class(['ms-1 whitespace-nowrap text-xs', 'text-emerald-600 dark:text-emerald-400' => $effective[$key]['allowed'], 'text-red-600 dark:text-red-400' => ! $effective[$key]['allowed']])>{{ $effective[$key]['allowed'] ? '● '.__('usable') : '● '.__('locked') }}</span>
                </span>
                <select wire:change="setAccess('{{ $key }}', $event.target.value)" class="w-32 shrink-0 rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Access to :module', ['module' => $module['label']]) }}">
                    <option value="default" @selected($current === 'default')>{{ __('Inherited') }}</option>
                    <option value="granted" @selected($current === 'granted')>{{ __('Released') }}</option>
                    <option value="blocked" @selected($current === 'blocked')>{{ __('Blocked') }}</option>
                </select>
                @if ($current !== 'default')
                    <label class="flex w-full items-center justify-end gap-2 text-xs text-gray-500 dark:text-gray-400 sm:w-auto">{{ __('until') }}
                        <input type="date" wire:model="until.{{ $key }}" wire:change="setAccess('{{ $key }}', '{{ $current }}')" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" title="{{ __('Valid until (optional)') }}">
                    </label>
                @endif
                <x-input-error :messages="$errors->get('until.'.$key)" class="w-full" />
            </li>
        @endforeach
    </ul>
</div>
