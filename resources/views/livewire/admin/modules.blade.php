<?php

use App\Models\Household;
use App\Models\ModuleEntitlement;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleCatalog;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin › Modules: which areas everybody gets ("included") and which need a release or – later – a
 * purchased license ("licensed"), with every release, lock, license and trial in effect.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function setPlan(string $module, string $plan): void
    {
        $this->authorize('admin');
        abort_unless(ModuleCatalog::exists($module) && in_array($plan, [ModuleAccess::INCLUDED, ModuleAccess::LICENSED], true), 422);
        ModuleAccess::savePlans(array_merge(ModuleAccess::plans(), [$module => $plan]));
        SecurityLog::record(Auth::user(), 'admin_action', details: 'module_plan '.$module.' '.$plan);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function removeEntry(int $id): void
    {
        $this->authorize('admin');
        $entry = ModuleEntitlement::findOrFail($id);
        $entry->delete();
        SecurityLog::record(Auth::user(), 'admin_action', details: 'module_entry_removed '.$entry->module.' '.($entry->household_id ? 'household #'.$entry->household_id : 'user #'.$entry->user_id).' ('.$entry->source.')');
    }

    public function with(): array
    {
        $entries = ModuleEntitlement::active()->with(['household:id,name', 'user:id,name,email'])->latest()->get();
        $householdCount = Household::count();

        return [
            'modules' => ModuleCatalog::all(),
            'plans' => ModuleAccess::plans(),
            'entries' => $entries,
            'summary' => collect(ModuleCatalog::all())->map(fn (array $module, string $key) => [
                'granted' => $entries->where('module', $key)->where('access', ModuleEntitlement::GRANTED)->count(),
                'blocked' => $entries->where('module', $key)->where('access', ModuleEntitlement::BLOCKED)->count(),
                'households' => $householdCount - $entries->where('module', $key)->where('access', ModuleEntitlement::GRANTED)->whereNotNull('household_id')->pluck('household_id')->unique()->count(),
            ])->all(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="rounded-xl bg-indigo-50 p-3 text-xs text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">🧩 {{ __('“Included” areas are open to everybody unless you lock them for a household or an account. “Licensed” areas need a release – today by you (Users or Households), later by a purchased license or a trial. Locks always win. Data of locked areas is kept.') }}</p>

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">{{ __('Modules') }}</h3>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($modules as $key => $module)
                        @php($licensed = ($plans[$key] ?? 'included') === 'licensed')
                        <li wire:key="plan-{{ $key }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5">
                            <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $module['icon'] }} {{ $module['label'] }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice(':count release|:count releases', $summary[$key]['granted']) }} · {{ trans_choice(':count lock|:count locks', $summary[$key]['blocked']) }}
                                    @if ($licensed && $summary[$key]['households'] > 0) · <span class="text-amber-700 dark:text-amber-300">{{ trans_choice(':count household without release|:count households without release', $summary[$key]['households']) }}</span>@endif
                                </span>
                            </span>
                            <select wire:change="setPlan('{{ $key }}', $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Plan for :module', ['module' => $module['label']]) }}">
                                <option value="included" @selected(! $licensed)>{{ __('Included') }}</option>
                                <option value="licensed" @selected($licensed)>{{ __('Licensed') }}</option>
                            </select>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">{{ __('Releases, locks and licenses in effect') }}</h3>
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @forelse ($entries as $entry)
                        <li wire:key="entry-{{ $entry->id }}" class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2">
                            <span class="text-gray-900 dark:text-gray-100">
                                {{ $entry->access === 'blocked' ? '🔒' : '✅' }} {{ $modules[$entry->module]['label'] ?? $entry->module }} ·
                                {{ $entry->household ? '🏠 '.$entry->household->name : '👤 '.($entry->user?->name ?? '–') }}
                                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ ['admin' => __('Administrator'), 'license' => __('License'), 'trial' => __('Trial')][$entry->source] ?? $entry->source }}@if ($entry->ends_at) · {{ __('until :date', ['date' => $entry->ends_at->format('d.m.Y')]) }}@endif</span>
                            </span>
                            <button type="button" wire:click="removeEntry({{ $entry->id }})" wire:confirm="{{ __('Remove this entry?') }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-gray-500 dark:text-gray-400">{{ __('Nothing released or locked yet – every area is open according to its plan.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
