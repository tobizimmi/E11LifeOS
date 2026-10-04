<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Automation;
use App\Models\HouseholdMode;
use App\Models\SmartHomeEntity;
use App\Support\Addons;
use App\Models\AutomationRun;
use App\Support\AiException;
use App\Support\Automation\AutomationAi;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\AutomationEngine;
use App\Support\Automation\Placeholders;
use App\Support\Drives\WebDavClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Automations across the tool: "when … only if … then …" with the calendar, trips, leave, modes,
 * Home Assistant and evcc; household modes such as holiday mode; templates and a run log.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $editing = false;

    public ?int $editingId = null;

    /**
     * @var array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, cooldown_minutes: int|string}
     */
    public array $form = ['name' => '', 'trigger' => ['type' => 'calendar'], 'conditions' => [], 'actions' => [], 'cooldown_minutes' => 1];

    public ?int $logFor = null;

    public bool $showHistory = false;

    public string $draftText = '';

    /** @var list<array{ok: bool, message: string}>|null */
    public ?array $testLog = null;

    /**
     * Yes/no values as "1"/"0" for the selects of the editor.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function forEditor(array $form): array
    {
        $flags = function (array $block) {
            if (array_key_exists('active', $block)) {
                $block['active'] = $block['active'] ? '1' : '0';
            }

            return $block;
        };
        $form['conditions'] = array_map(function (array $block) use ($flags) {
            if (isset($block['conditions']) && is_array($block['conditions'])) {
                $block['conditions'] = array_map($flags, $block['conditions']);
            }

            return $flags($block);
        }, $form['conditions']);
        $form['actions'] = array_map($flags, $form['actions']);

        return $form;
    }

    private function findAutomation(int $id): Automation
    {
        return Automation::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function create(?string $template = null): void
    {
        $preset = AutomationCatalog::templates()[$template] ?? null;
        $this->reset('editingId');
        $this->form = $this->forEditor($preset
            ? ['name' => $preset['name'], 'trigger' => $preset['trigger'], 'conditions' => $preset['conditions'], 'actions' => $preset['actions'], 'cooldown_minutes' => 1]
            : ['name' => '', 'trigger' => ['type' => 'calendar'] + AutomationCatalog::triggers()['calendar']['defaults'], 'conditions' => [], 'actions' => [], 'cooldown_minutes' => 1]);
        $this->resetValidation();
        $this->testLog = null;
        $this->editing = true;
    }

    /**
     * Fills the editor with an AI draft of the description; nothing is saved yet.
     */
    public function draft(AutomationAi $ai): void
    {
        $this->validate(['draftText' => ['required', 'string', 'min:8', 'max:1000']], attributes: ['draftText' => __('Description')]);

        if (RateLimiter::tooManyAttempts('automation-draft:'.Auth::id(), 20)) {
            $this->addError('draftText', __('Too many drafts – please try again in an hour.'));

            return;
        }

        RateLimiter::hit('automation-draft:'.Auth::id(), 3600);

        try {
            $draft = $ai->draft($this->draftText, $this->householdId, Auth::user()->canUseAddon(Addons::SMART_HOME));
        } catch (AiException $exception) {
            $this->addError('draftText', $exception->getMessage());

            return;
        }

        $this->reset('editingId');
        $this->form = $this->forEditor(['name' => $draft['name'] ?: mb_strimwidth($this->draftText, 0, 60, '…'), 'trigger' => $draft['trigger'], 'conditions' => $draft['conditions'], 'actions' => $draft['actions'], 'cooldown_minutes' => 1]);
        $this->resetValidation();
        $this->testLog = null;
        $this->editing = true;
        $this->dispatch('toast', message: __('Draft ready – check it, test it and save.'));
    }

    /**
     * Installs all automations of a package; ones that still need a device start paused.
     */
    public function installPackage(string $key): void
    {
        $package = AutomationCatalog::packages()[$key] ?? abort(404);
        $smartHome = Auth::user()->canUseAddon(Addons::SMART_HOME);
        $existing = Automation::where('household_id', $this->householdId)->pluck('name')->all();
        $installed = 0;
        $paused = 0;

        foreach ($package['automations'] as $automation) {
            $blocks = [$automation['trigger'], ...$automation['conditions'], ...$automation['actions']];

            if (in_array($automation['name'], $existing, true) || (! $smartHome && collect($blocks)->contains(fn (array $block) => in_array($block['type'], AutomationCatalog::SMART_HOME_ONLY, true)))) {
                continue;
            }

            $complete = ! collect($blocks)->contains(fn (array $block) => AutomationCatalog::needsDevice($block));
            Automation::create([
                'household_id' => $this->householdId, 'user_id' => Auth::id(), 'name' => $automation['name'], 'active' => $complete,
                'trigger' => AutomationCatalog::clean($automation['trigger'], 'trigger'),
                'conditions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'condition'), $automation['conditions']))),
                'actions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'action'), $automation['actions']))),
                'cooldown_minutes' => 1,
            ]);
            $installed++;
            $paused += $complete ? 0 : 1;
        }

        $this->dispatch('toast', message: $installed === 0 ? __('All automations of this package are already there.')
            : trans_choice(':count automation installed.|:count automations installed.', $installed).($paused ? ' '.trans_choice(':count needs a device – open it, choose one and switch it on.|:count need a device – open them, choose one and switch them on.', $paused) : ''));
    }

    public function edit(int $id): void
    {
        $automation = $this->findAutomation($id);
        $this->editingId = $automation->id;
        $this->form = $this->forEditor(['name' => $automation->name, 'trigger' => $automation->trigger, 'conditions' => $automation->conditions ?? [], 'actions' => $automation->actions ?? [], 'cooldown_minutes' => $automation->cooldown_minutes]);
        $this->testLog = null;
        $this->editing = true;
    }

    public function setTrigger(string $type): void
    {
        abort_unless(isset(AutomationCatalog::triggers()[$type]), 422);
        $this->form['trigger'] = ['type' => $type] + AutomationCatalog::triggers()[$type]['defaults'];
    }

    public function addCondition(string $type): void
    {
        abort_unless(isset(AutomationCatalog::conditions()[$type]), 422);
        $this->form['conditions'][] = $this->forEditor(['conditions' => [['type' => $type] + AutomationCatalog::conditions()[$type]['defaults']], 'actions' => []])['conditions'][0];
    }

    public function addGroupCondition(int $index, string $type): void
    {
        abort_unless(isset(AutomationCatalog::groupConditions()[$type]) && ($this->form['conditions'][$index]['type'] ?? '') === 'any', 422);
        $this->form['conditions'][$index]['conditions'][] = $this->forEditor(['conditions' => [['type' => $type] + AutomationCatalog::groupConditions()[$type]['defaults']], 'actions' => []])['conditions'][0];
    }

    public function removeGroupCondition(int $index, int $inner): void
    {
        unset($this->form['conditions'][$index]['conditions'][$inner]);
        $this->form['conditions'][$index]['conditions'] = array_values($this->form['conditions'][$index]['conditions'] ?? []);
    }

    public function removeCondition(int $index): void
    {
        unset($this->form['conditions'][$index]);
        $this->form['conditions'] = array_values($this->form['conditions']);
    }

    public function addAction(string $type): void
    {
        abort_unless(isset(AutomationCatalog::actions()[$type]), 422);
        $this->form['actions'][] = $this->forEditor(['conditions' => [], 'actions' => [['type' => $type] + AutomationCatalog::actions()[$type]['defaults']]])['actions'][0];
    }

    public function removeAction(int $index): void
    {
        unset($this->form['actions'][$index]);
        $this->form['actions'] = array_values($this->form['actions']);
    }

    /**
     * The editor's blocks as they would be stored.
     *
     * @return array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, cooldown_minutes: int}
     */
    private function validatedForm(): array
    {
        $time = ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $this->validate([
            'form.name' => ['required', 'string', 'max:150'],
            'form.trigger.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::triggers()))],
            'form.trigger.at' => ['nullable', ...$time],
            'form.conditions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::conditions()))],
            'form.conditions.*.from' => ['nullable', ...$time],
            'form.conditions.*.until' => ['nullable', ...$time],
            'form.conditions.*.conditions' => ['nullable', 'array', 'max:10'],
            'form.conditions.*.conditions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::groupConditions()))],
            'form.conditions.*.conditions.*.from' => ['nullable', ...$time],
            'form.conditions.*.conditions.*.until' => ['nullable', ...$time],
            'form.actions' => ['required', 'array', 'min:1', 'max:20'],
            'form.actions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::actions()))],
            'form.actions.*.from' => ['nullable', ...$time],
            'form.actions.*.until' => ['nullable', ...$time],
            'form.actions.*.time' => ['nullable', ...$time],
            'form.actions.*.minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'form.actions.*.url' => ['nullable', 'string', 'max:500', fn ($attribute, $value, $fail) => blank($value) || WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed. Use an address in the home network that goes through the VPN, or a public address.'))],
            'form.actions.*.data' => ['nullable', 'string', 'max:2000', fn ($attribute, $value, $fail) => blank($value) || is_array(json_decode((string) $value, true)) ? null : $fail(__('Enter valid JSON, e.g. {"brightness_pct": 40}.'))],
            'form.cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ], attributes: ['form.actions' => __('Actions')]);

        return [
            'name' => $this->form['name'],
            'trigger' => AutomationCatalog::clean($this->form['trigger'], 'trigger') ?? ['type' => 'manual'],
            'conditions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'condition'), $this->form['conditions']))),
            'actions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'action'), $this->form['actions']))),
            'cooldown_minutes' => (int) $this->form['cooldown_minutes'],
        ];
    }

    public function save(): void
    {
        $attributes = $this->validatedForm();

        if ($this->editingId) {
            // A changed trigger starts from scratch, so a state that already holds fires again.
            $automation = $this->findAutomation($this->editingId);
            $automation->update($attributes + ($automation->trigger != $attributes['trigger'] ? ['trigger_state' => false] : []));
        } else {
            Automation::create($attributes + ['household_id' => $this->householdId, 'user_id' => Auth::id(), 'active' => true]);
        }

        $this->reset(['editing', 'editingId']);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(int $id): void
    {
        $this->findAutomation($id)->delete();
        $this->reset(['editing', 'editingId']);
    }

    public function toggleActive(int $id): void
    {
        $automation = $this->findAutomation($id);
        $automation->update(['active' => ! $automation->active]);
    }

    /**
     * Test run of the editor's state: shows what would happen now, without doing anything.
     */
    public function testRun(AutomationEngine $engine): void
    {
        $automation = new Automation($this->validatedForm() + ['household_id' => $this->householdId, 'user_id' => Auth::id()]);
        $automation->setRelation('user', Auth::user());
        $this->testLog = $engine->dryRun($automation);
    }

    public function runNow(int $id, AutomationEngine $engine): void
    {
        $run = $engine->run($this->findAutomation($id), __('Started by hand by :name', ['name' => Auth::user()->name]), manual: true);
        $this->logFor = $id;
        $this->dispatch('toast', message: $run->status === 'done' ? __('Done.') : __('Finished with errors – see the log.'));
    }

    public function toggleMode(string $key, AutomationEngine $engine): void
    {
        $mode = HouseholdMode::forHousehold($this->householdId)->firstWhere('key', $key) ?? abort(404);
        $engine->setMode($this->householdId, $key, ! $mode->active);
    }

    public function with(): array
    {
        $smartHome = Auth::user()->canUseAddon(Addons::SMART_HOME);

        return [
            'automations' => Automation::where('household_id', $this->householdId)->with(['user', 'runs' => fn ($query) => $query->latest()->limit(1)])->orderBy('name')->get(),
            'modes' => HouseholdMode::forHousehold($this->householdId),
            'templates' => AutomationCatalog::templates(),
            'triggers' => AutomationCatalog::triggers(),
            'conditions' => AutomationCatalog::conditions(),
            'actions' => collect(AutomationCatalog::actions())->reject(fn ($action, string $type) => ! $smartHome && in_array($type, AutomationCatalog::SMART_HOME_ONLY, true))->all(),
            'groupConditions' => collect(AutomationCatalog::groupConditions())->reject(fn ($condition, string $type) => ! $smartHome && in_array($type, AutomationCatalog::SMART_HOME_ONLY, true))->all(),
            'packages' => AutomationCatalog::packages(),
            'placeholders' => Placeholders::help(),
            'aiReady' => AutomationAi::isConfigured(),
            'history' => $this->showHistory ? AutomationRun::whereIn('automation_id', Automation::where('household_id', $this->householdId)->select('id'))->with('automation')->latest()->limit(40)->get() : collect(),
            'entities' => $smartHome ? SmartHomeEntity::where('household_id', $this->householdId)->orderBy('name')->get() : collect(),
            'log' => $this->logFor ? $this->findAutomation($this->logFor)->runs()->latest()->limit(15)->get() : collect(),
            'smartHome' => $smartHome,
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $small = 'rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $weekdays = collect(range(1, 7))->mapWithKeys(fn ($day) => [$day => now()->startOfWeek()->addDays($day - 1)->translatedFormat('D')]);
@endphp

<div x-on:page-primary.window="$wire.create()">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Automations') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('New automation') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Modes of the household: switched by hand or by automations. --}}
            <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Modes') }}</h3>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($modes as $mode)
                        <button type="button" wire:click="toggleMode('{{ $mode->key }}')" wire:key="mode-{{ $mode->id }}" aria-pressed="{{ $mode->active ? 'true' : 'false' }}"
                            @class(['rounded-full px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $mode->active, 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => ! $mode->active])>{{ $mode->icon }} {{ $mode->name }}</button>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Modes switch automations on, e.g. holiday mode starts the presence simulation.') }}</p>
            </section>

            @if ($aiReady && ! $editing)
                <form wire:submit="draft" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <label for="automation-draft" class="text-sm font-semibold text-gray-900 dark:text-gray-100">✨ {{ __('Describe an automation') }}</label>
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                        <input id="automation-draft" type="text" wire:model="draftText" maxlength="1000" class="{{ $input }} mt-0! flex-1" placeholder="{{ __('e.g. When the last one leaves, ask whether all lights should go off') }}">
                        <x-primary-button wire:loading.attr="disabled" wire:target="draft" class="justify-center">{{ __('Create draft') }}</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('draftText')" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('The AI only fills in the editor – you check, test and save.') }}</p>
                </form>
            @endif

            @if ($editing)
                <form wire:submit="save" class="space-y-4 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="form.name" class="{{ $input }}"><x-input-error :messages="$errors->get('form.name')" /></label>

                    {{-- WHEN --}}
                    <fieldset class="rounded-xl bg-indigo-50/60 p-4 dark:bg-indigo-900/20">
                        <legend class="px-1 text-sm font-semibold text-indigo-900 dark:text-indigo-200">{{ __('When') }}</legend>
                        <select x-on:change="$wire.setTrigger($event.target.value)" class="{{ $small }}" aria-label="{{ __('Trigger') }}">
                            @foreach ($triggers as $type => $trigger)
                                @if ($smartHome || ! in_array($type, \App\Support\Automation\AutomationCatalog::SMART_HOME_ONLY, true))
                                    <option value="{{ $type }}" @selected($form['trigger']['type'] === $type)>{{ $trigger['icon'] }} {{ $trigger['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            @include('automations.block-fields', ['path' => 'form.trigger', 'block' => $form['trigger'], 'kind' => 'trigger'])
                        </div>
                    </fieldset>

                    {{-- ONLY IF --}}
                    <fieldset class="rounded-xl bg-amber-50/60 p-4 dark:bg-amber-900/20">
                        <legend class="px-1 text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('Only if (optional)') }}</legend>
                        @foreach ($form['conditions'] as $index => $condition)
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300" wire:key="condition-{{ $index }}-{{ $condition['type'] }}">
                                <span class="font-medium">{{ $conditions[$condition['type']]['icon'] ?? '' }} {{ $conditions[$condition['type']]['label'] ?? '' }}</span>
                                @if ($condition['type'] === 'any')
                                    <button type="button" wire:click="removeCondition({{ $index }})" class="ms-auto text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}">✕</button>
                                    <div class="w-full space-y-2 rounded-lg border border-amber-200 p-3 dark:border-amber-800/60">
                                        @foreach ($condition['conditions'] ?? [] as $inner => $groupCondition)
                                            <div class="flex flex-wrap items-center gap-2" wire:key="condition-{{ $index }}-{{ $inner }}-{{ $groupCondition['type'] }}">
                                                @if ($inner > 0)<span class="text-xs font-semibold uppercase text-amber-800 dark:text-amber-300">{{ __('or') }}</span>@endif
                                                <span class="font-medium">{{ $groupConditions[$groupCondition['type']]['icon'] ?? '' }} {{ $groupConditions[$groupCondition['type']]['label'] ?? '' }}</span>
                                                @include('automations.block-fields', ['path' => 'form.conditions.'.$index.'.conditions.'.$inner, 'block' => $groupCondition, 'kind' => 'condition'])
                                                <button type="button" wire:click="removeGroupCondition({{ $index }}, {{ $inner }})" class="ms-auto text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}">✕</button>
                                            </div>
                                        @endforeach
                                        <select x-on:change="$wire.addGroupCondition({{ $index }}, $event.target.value); $event.target.value = ''" class="{{ $small }}" aria-label="{{ __('Add alternative') }}">
                                            <option value="">+ {{ __('Add alternative') }}</option>
                                            @foreach ($groupConditions as $type => $groupCondition)
                                                <option value="{{ $type }}">{{ $groupCondition['icon'] }} {{ $groupCondition['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @else
                                    @include('automations.block-fields', ['path' => 'form.conditions.'.$index, 'block' => $condition, 'kind' => 'condition'])
                                    <button type="button" wire:click="removeCondition({{ $index }})" class="ms-auto text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}">✕</button>
                                @endif
                            </div>
                        @endforeach
                        <select x-on:change="$wire.addCondition($event.target.value); $event.target.value = ''" class="{{ $small }}" aria-label="{{ __('Add condition') }}">
                            <option value="">+ {{ __('Add condition') }}</option>
                            @foreach ($conditions as $type => $condition)
                                @if ($smartHome || ! in_array($type, \App\Support\Automation\AutomationCatalog::SMART_HOME_ONLY, true))
                                    <option value="{{ $type }}">{{ $condition['icon'] }} {{ $condition['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </fieldset>

                    {{-- THEN --}}
                    <fieldset class="rounded-xl bg-emerald-50/60 p-4 dark:bg-emerald-900/20">
                        <legend class="px-1 text-sm font-semibold text-emerald-900 dark:text-emerald-200">{{ __('Then') }}</legend>
                        @foreach ($form['actions'] as $index => $action)
                            <div class="mb-3 flex flex-wrap items-center gap-2 border-b border-emerald-100 pb-3 text-sm text-gray-700 last:border-0 dark:border-emerald-900/40 dark:text-gray-300" wire:key="action-{{ $index }}-{{ $action['type'] }}">
                                <span class="font-medium">{{ $index + 1 }}. {{ $actions[$action['type']]['icon'] ?? '' }} {{ $actions[$action['type']]['label'] ?? $action['type'] }}</span>
                                @include('automations.block-fields', ['path' => 'form.actions.'.$index, 'block' => $action, 'kind' => 'action'])
                                <button type="button" wire:click="removeAction({{ $index }})" class="ms-auto text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}">✕</button>
                                <x-input-error :messages="$errors->get('form.actions.'.$index.'.data')" class="w-full" />
                                <x-input-error :messages="$errors->get('form.actions.'.$index.'.url')" class="w-full" />
                            </div>
                        @endforeach
                        <select x-on:change="$wire.addAction($event.target.value); $event.target.value = ''" class="{{ $small }}" aria-label="{{ __('Add action') }}">
                            <option value="">+ {{ __('Add action') }}</option>
                            @foreach ($actions as $type => $action)
                                <option value="{{ $type }}">{{ $action['icon'] }} {{ $action['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('form.actions')" class="mt-1" />
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Placeholders in texts:') }} @foreach ($placeholders as $placeholder => $meaning)<code class="rounded-sm bg-white/70 px-1 dark:bg-gray-900/50">{{ $placeholder }}</code> {{ $meaning }}@if (! $loop->last), @endif @endforeach</p>
                    </fieldset>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Pause between runs (minutes)') }} <input type="number" min="1" max="1440" wire:model="form.cooldown_minutes" class="w-20 {{ $small }}"></label>
                    <x-input-error :messages="$errors->get('form.cooldown_minutes')" />

                    <div class="flex flex-wrap gap-2">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="testRun">🧪 {{ __('Test run') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this automation?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>

                    @if ($testLog !== null)
                        <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40" role="status">
                            <p class="font-medium text-gray-900 dark:text-gray-100">🧪 {{ __('Test run – nothing was switched or sent') }}</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($testLog as $entry)
                                    <li class="{{ $entry['ok'] ? 'text-gray-700 dark:text-gray-300' : 'text-amber-800 dark:text-amber-300' }}">{{ $entry['ok'] ? '✓' : '✕' }} {{ $entry['message'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </form>
            @endif

            @if ($automations->isNotEmpty())
                <ul class="divide-y divide-gray-100 rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($automations as $automation)
                        @php($last = $automation->runs->first())
                        <li class="p-4" wire:key="automation-{{ $automation->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p @class(['font-medium', 'text-gray-900 dark:text-gray-100' => $automation->active, 'text-gray-400 line-through' => ! $automation->active])>{{ $automation->name }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">
                                        {{ $triggers[$automation->trigger['type']]['icon'] ?? '' }} {{ \App\Support\Automation\AutomationCatalog::describe($automation->trigger) }}
                                        → {{ collect($automation->actions)->map(fn ($action) => \App\Support\Automation\AutomationCatalog::describe($action))->implode(' · ') }}
                                    </p>
                                    @if ($last)
                                        <p class="text-xs {{ in_array($last->status, ['failed', 'partial'], true) ? 'text-red-700 dark:text-red-300' : 'text-gray-500 dark:text-gray-400' }}">{{ __('Last run :time', ['time' => $last->created_at->diffForHumans()]) }} · {{ ['done' => '✓', 'partial' => '⚠️', 'failed' => '✕', 'skipped' => '⏭️'][$last->status] ?? '' }} {{ $last->reason }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                                    <button type="button" wire:click="runNow({{ $automation->id }})" class="text-indigo-600 underline dark:text-indigo-400">▶ {{ __('Run now') }}</button>
                                    <button type="button" wire:click="$set('logFor', {{ $logFor === $automation->id ? 'null' : $automation->id }})" class="text-gray-600 underline dark:text-gray-300">{{ __('Log') }}</button>
                                    <button type="button" wire:click="edit({{ $automation->id }})" class="text-gray-600 underline dark:text-gray-300">{{ __('Edit') }}</button>
                                    <label class="flex items-center gap-1"><input type="checkbox" @checked($automation->active) wire:click="toggleActive({{ $automation->id }})" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Active') }}</label>
                                </div>
                            </div>
                            @if ($logFor === $automation->id)
                                <ul class="mt-3 space-y-2 rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900/40">
                                    @forelse ($log as $run)
                                        <li wire:key="run-{{ $run->id }}">
                                            <p class="font-medium text-gray-800 dark:text-gray-200">{{ $run->created_at->format('d.m. H:i') }} · {{ $run->reason }}</p>
                                            @foreach ($run->log ?? [] as $entry)
                                                <p class="{{ $entry['ok'] ? 'text-gray-600 dark:text-gray-400' : 'text-red-700 dark:text-red-300' }}">{{ $entry['ok'] ? '✓' : '✕' }} {{ $entry['message'] }}</p>
                                            @endforeach
                                        </li>
                                    @empty
                                        <li class="text-gray-500">{{ __('Not run yet.') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <section>
                <button type="button" wire:click="$toggle('showHistory')" aria-expanded="{{ $showHistory ? 'true' : 'false' }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $showHistory ? '▾' : '▸' }} {{ __('History of all runs') }}</button>
                @if ($showHistory)
                    <ul class="mt-2 divide-y divide-gray-100 rounded-2xl bg-white text-sm shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                        @forelse ($history as $run)
                            <li class="p-3" wire:key="history-{{ $run->id }}" x-data="{ open: false }">
                                <button type="button" x-on:click="open = ! open" class="flex w-full items-start gap-2 text-start">
                                    <span aria-hidden="true">{{ ['done' => '✓', 'partial' => '⚠️', 'failed' => '✕', 'skipped' => '⏭️'][$run->status] ?? '' }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $run->automation?->name }}</span>
                                        <span class="block text-xs text-gray-600 dark:text-gray-400">{{ $run->created_at->format('d.m. H:i') }} · {{ $run->reason }}</span>
                                    </span>
                                </button>
                                <ul x-show="open" x-cloak class="mt-2 space-y-0.5 ps-6 text-xs">
                                    @foreach ($run->log ?? [] as $entry)
                                        <li class="{{ $entry['ok'] ? 'text-gray-600 dark:text-gray-400' : 'text-red-700 dark:text-red-300' }}">{{ $entry['ok'] ? '✓' : '✕' }} {{ $entry['message'] }}</li>
                                    @endforeach
                                </ul>
                            </li>
                        @empty
                            <li class="p-3 text-gray-500 dark:text-gray-400">{{ __('Not run yet.') }}</li>
                        @endforelse
                    </ul>
                @endif
            </section>

            <section>
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Packages – install with one click') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($packages as $key => $package)
                        <div class="flex flex-col rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800" wire:key="package-{{ $key }}">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $package['icon'] }} {{ $package['name'] }}</p>
                            <p class="mt-1 flex-1 text-xs text-gray-600 dark:text-gray-400">{{ $package['description'] }}</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ collect($package['automations'])->pluck('name')->implode(' · ') }}</p>
                            <button type="button" wire:click="installPackage('{{ $key }}')" class="mt-3 self-start rounded-full bg-indigo-600 px-3 py-1.5 text-sm text-white active:scale-[0.98]">{{ __('Install') }}</button>
                        </div>
                    @endforeach
                </div>
            </section>

            <section>
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Templates') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($templates as $key => $template)
                        <button type="button" wire:click="create('{{ $key }}')" wire:key="template-{{ $key }}" class="rounded-2xl bg-white p-4 text-start shadow-xs transition hover:ring-1 hover:ring-indigo-200 active:scale-[0.99] dark:bg-gray-800 dark:hover:ring-indigo-800">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $template['icon'] }} {{ $template['name'] }}</p>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $template['description'] }}</p>
                        </button>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</div>
