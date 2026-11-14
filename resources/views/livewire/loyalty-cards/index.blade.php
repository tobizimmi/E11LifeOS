<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\LoyaltyCard;
use App\Support\CardCode;
use App\Support\CardImages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold, WithFileUploads;

    public bool $adding = false;

    public ?int $editingId = null;

    /** Card shown large (as a scannable card), null = none open. */
    public ?int $showingId = null;

    public string $name = '';

    public string $code = '';

    public string $codeFormat = 'QR_CODE';

    public string $color = '';

    public string $notes = '';

    public bool $private = false;

    public string $linkUrl = '';

    /** @var array<int, array{label: string, value: string}> */
    public array $details = [];

    public $frontImage = null;

    public $backImage = null;

    public bool $removeFront = false;

    public bool $removeBack = false;

    private function find(int $id): LoyaltyCard
    {
        return LoyaltyCard::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->reset('adding', 'editingId', 'name', 'code', 'codeFormat', 'color', 'notes', 'private', 'linkUrl', 'details', 'frontImage', 'backImage', 'removeFront', 'removeBack');
        $this->resetValidation();
    }

    public function startAdding(): void
    {
        $this->resetForm();
        $this->adding = true;
    }

    public function startEditing(int $id): void
    {
        $card = $this->find($id);

        $this->editingId = $card->id;
        $this->name = $card->name;
        $this->code = $card->code;
        $this->codeFormat = $card->code_format;
        $this->color = (string) $card->color;
        $this->notes = (string) $card->notes;
        $this->private = $card->isPrivate();
        $this->linkUrl = (string) $card->link_url;
        $this->details = $card->detailRows();
        $this->reset('frontImage', 'backImage', 'removeFront', 'removeBack');
        $this->adding = true;
        $this->showingId = null;
        $this->resetValidation();
    }

    public function addDetail(): void
    {
        $this->details[] = ['label' => '', 'value' => ''];
    }

    public function removeDetail(int $index): void
    {
        unset($this->details[$index]);
        $this->details = array_values($this->details);
    }

    /**
     * Fills the form from a scanned or uploaded code (called by the JS scanner). Formats the app
     * does not offer in the picker are mapped to the matching supported one, keeping the dimension.
     */
    public function setScanned(string $code, string $format): void
    {
        $format = strtoupper(trim($format));

        $this->code = trim($code);
        $this->codeFormat = in_array($format, CardCode::FORMATS, true)
            ? $format
            : (CardCode::isTwoDimensional($format) ? 'QR_CODE' : 'CODE_128');
        $this->adding = true;
        $this->showingId = null;
        $this->resetValidation();
        $this->dispatch('toast', message: __('Code recognised – add a name and save.'));
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:500'],
            'codeFormat' => ['required', Rule::in(CardCode::FORMATS)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'linkUrl' => ['nullable', 'string', 'max:500'],
            'frontImage' => ['nullable', 'image', 'max:8192'],
            'backImage' => ['nullable', 'image', 'max:8192'],
            'details' => ['array', 'max:20'],
            'details.*.label' => ['nullable', 'string', 'max:60'],
            'details.*.value' => ['nullable', 'string', 'max:200'],
        ]);

        $card = $this->editingId
            ? $this->find($this->editingId)
            : new LoyaltyCard(['household_id' => $this->householdId, 'created_by' => Auth::id()]);

        $card->fill([
            'name' => trim($this->name),
            'code' => trim($this->code),
            'code_format' => $this->codeFormat,
            'color' => $this->color !== '' ? $this->color : null,
            'notes' => $this->notes !== '' ? trim($this->notes) : null,
            'link_url' => trim($this->linkUrl) !== '' ? trim($this->linkUrl) : null,
            'details' => $this->cleanedDetails() ?: null,
            'visibility' => $this->private ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
        ]);

        $this->applyImage($card, 'front_image_path', $this->frontImage, $this->removeFront);
        $this->applyImage($card, 'back_image_path', $this->backImage, $this->removeBack);

        $card->save();

        $this->resetForm();
        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Stores a new upload (replacing any old file) or removes the current one.
     */
    private function applyImage(LoyaltyCard $card, string $column, $upload, bool $remove): void
    {
        if ($upload) {
            CardImages::delete($card->{$column});
            $card->{$column} = CardImages::store($upload, (int) $this->householdId);
        } elseif ($remove) {
            CardImages::delete($card->{$column});
            $card->{$column} = null;
        }
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function cleanedDetails(): array
    {
        return collect($this->details)
            ->map(fn ($row) => ['label' => trim((string) ($row['label'] ?? '')), 'value' => trim((string) ($row['value'] ?? ''))])
            ->filter(fn (array $row) => $row['label'] !== '' || $row['value'] !== '')
            ->values()
            ->all();
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
        $this->showingId = null;
    }

    public function showCard(int $id): void
    {
        $this->showingId = $this->find($id)->id;
    }

    public function closeCard(): void
    {
        $this->showingId = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'cards' => LoyaltyCard::query()->visibleTo(Auth::user(), (int) $this->householdId)
                ->orderBy('position')->orderBy('name')->get(),
            'showing' => $this->showingId !== null ? $this->find($this->showingId) : null,
            'editingCard' => $this->editingId !== null ? $this->find($this->editingId) : null,
            'formats' => CardCode::FORMATS,
        ];
    }
}; ?>

@php
    $formatLabel = fn (string $format) => \App\Support\CardCode::label($format);
    $swatches = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#4b5563'];
@endphp

<div x-data="cardScanner">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💳 {{ __('Loyalty cards') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Keep your store and loyalty cards here and show them at the till – scanned, uploaded or typed in.') }}</p>
                <div class="flex gap-2">
                    <button type="button" x-on:click="open()" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">📷 {{ __('Scan') }}</button>
                    <x-primary-button type="button" wire:click="startAdding">+ {{ __('New card') }}</x-primary-button>
                </div>
            </div>

            @if ($cards->isEmpty())
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
                    <p class="text-4xl">💳</p>
                    <p class="mt-2 font-medium text-gray-900 dark:text-gray-100">{{ __('No cards yet') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Add your first card – scan it, upload a photo of the code, or type the number.') }}</p>
                </div>
            @else
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cards as $card)
                        <button type="button" wire:key="card-{{ $card->id }}" wire:click="showCard({{ $card->id }})"
                            class="group relative flex aspect-[1.586/1] flex-col justify-between overflow-hidden rounded-2xl p-4 text-left text-white shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                            style="background: linear-gradient(135deg, {{ $card->color ?? '#4b5563' }}, {{ $card->color ? $card->color.'cc' : '#111827' }});">
                            @if ($card->frontImageUrl())
                                <img src="{{ $card->frontImageUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover" />
                                <span class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-black/10"></span>
                            @endif
                            <span class="relative flex items-start justify-between gap-2">
                                <span class="text-lg font-semibold drop-shadow-sm">{{ $card->name }}</span>
                                @if ($card->isPrivate())
                                    <span class="rounded-full bg-black/30 px-2 py-0.5 text-xs">🔒</span>
                                @endif
                            </span>
                            <span class="relative flex items-center justify-between gap-2">
                                <span class="font-mono text-sm tracking-wider text-white/90">{{ \Illuminate\Support\Str::limit($card->code, 22) }}</span>
                                <span class="rounded bg-black/25 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">{{ $formatLabel($card->code_format) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Card detail (shown large, scannable) --}}
    @if ($showing)
        <div class="fixed inset-0 z-[60] flex items-end justify-center overflow-y-auto bg-gray-900/60 p-4 sm:items-center" x-on:keydown.escape.window="$wire.closeCard()" wire:click.self="closeCard">
            <div class="my-auto max-h-[calc(100dvh-2rem)] w-full max-w-md space-y-4 overflow-y-auto overscroll-contain rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true" aria-label="{{ $showing->name }}">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $showing->name }}</h3>
                    <button type="button" wire:click="closeCard" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-5 w-5" stroke-width="2" /></button>
                </div>

                @if ($showing->frontImageUrl() || $showing->backImageUrl())
                    <div class="flex gap-3">
                        @if ($showing->frontImageUrl())
                            <img src="{{ $showing->frontImageUrl() }}" alt="{{ __('Front') }}" class="min-w-0 flex-1 rounded-xl object-cover ring-1 ring-gray-200 dark:ring-gray-700" />
                        @endif
                        @if ($showing->backImageUrl())
                            <img src="{{ $showing->backImageUrl() }}" alt="{{ __('Back') }}" class="min-w-0 flex-1 rounded-xl object-cover ring-1 ring-gray-200 dark:ring-gray-700" />
                        @endif
                    </div>
                @endif

                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200 dark:ring-gray-700">
                    <div class="flex justify-center">{!! $showing->codeSvg() !!}</div>
                    <p class="mt-3 text-center font-mono text-lg tracking-widest text-gray-900 select-all">{{ $showing->code }}</p>
                    <p class="mt-1 text-center text-xs text-gray-400">{{ $showing->formatLabel() }}</p>
                </div>

                @if ($showing->detailRows() !== [])
                    <dl class="divide-y divide-gray-100 rounded-xl ring-1 ring-gray-200 dark:divide-gray-700 dark:ring-gray-700">
                        @foreach ($showing->detailRows() as $row)
                            <div class="flex items-baseline justify-between gap-3 px-3 py-2 text-sm">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ $row['label'] ?: '—' }}</dt>
                                <dd class="min-w-0 break-words text-right font-medium text-gray-900 select-all dark:text-gray-100">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($showing->notes)
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $showing->notes }}</p>
                @endif

                @if ($showing->safeLinkUrl())
                    <a href="{{ $showing->safeLinkUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">🔗 {{ __('Open account') }}</a>
                @elseif ($showing->link_url)
                    <p class="break-all rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">🔗 {{ $showing->link_url }}</p>
                @endif

                <div class="flex items-center justify-between gap-2 pt-1">
                    <button type="button" wire:click="startEditing({{ $showing->id }})" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Edit') }}</button>
                    <button type="button" wire:click="delete({{ $showing->id }})" wire:confirm="{{ __('Delete this card?') }}" class="text-sm text-red-600 hover:underline">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Add / edit form --}}
    @if ($adding)
        <div class="fixed inset-0 z-[60] flex items-end justify-center overflow-y-auto bg-gray-900/60 p-4 sm:items-center" x-on:keydown.escape.window="$wire.set('adding', false)" wire:click.self="$set('adding', false)">
            <form wire:submit="save" class="my-auto max-h-[calc(100dvh-2rem)] w-full max-w-lg space-y-4 overflow-y-auto overscroll-contain rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit card') : __('New card') }}</h3>
                    <button type="button" wire:click="$set('adding', false)" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-5 w-5" stroke-width="2" /></button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" x-on:click="open()" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-700">📷 {{ __('Scan code') }}</button>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-700">
                        🖼️ {{ __('Upload image') }}
                        <input type="file" accept="image/*" class="hidden" x-on:change="decodeFile($event)" />
                    </label>
                    <span x-show="fileError" x-cloak class="self-center text-xs text-red-600 dark:text-red-400">{{ __('No code found in the image.') }}</span>
                </div>

                <div>
                    <x-input-label for="card-name" :value="__('Name')" />
                    <x-text-input wire:model="name" id="card-name" class="mt-1 block w-full" placeholder="REWE, DM, Payback …" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-input-label for="card-code" :value="__('Code / number')" />
                        <x-text-input wire:model="code" id="card-code" class="mt-1 block w-full font-mono" />
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="card-format" :value="__('Code type')" />
                        <select wire:model="codeFormat" id="card-format" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($formats as $format)
                                <option value="{{ $format }}">{{ $formatLabel($format) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Color') }}</span>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="$set('color', '')" @class(['h-7 w-7 rounded-full bg-gray-500 ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-800', 'ring-indigo-500' => $color === '', 'ring-transparent' => $color !== '']) aria-label="{{ __('No color') }}"></button>
                        @foreach ($swatches as $swatch)
                            <button type="button" wire:click="$set('color', '{{ $swatch }}')" @class(['h-7 w-7 rounded-full ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-800', 'ring-indigo-500' => $color === $swatch, 'ring-transparent' => $color !== $swatch]) style="background: {{ $swatch }}" aria-label="{{ $swatch }}"></button>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('color')" class="mt-1" />
                </div>

                {{-- Card photos (front/back) so it looks like the real card --}}
                <div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Card photos (optional)') }}</span>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach (['front' => __('Front / logo'), 'back' => __('Back')] as $side => $sideLabel)
                            @php
                                $upload = $side === 'front' ? $frontImage : $backImage;
                                $removeFlag = $side === 'front' ? 'removeFront' : 'removeBack';
                                $existingUrl = $side === 'front' ? $editingCard?->frontImageUrl() : $editingCard?->backImageUrl();
                            @endphp
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $sideLabel }}</span>
                                <div class="mt-2 flex items-center gap-3">
                                    @if ($upload && method_exists($upload, 'temporaryUrl') && $upload->isPreviewable())
                                        <img src="{{ $upload->temporaryUrl() }}" alt="" class="h-16 w-24 rounded-md object-cover" />
                                    @elseif ($existingUrl && ! ($side === 'front' ? $removeFront : $removeBack))
                                        <img src="{{ $existingUrl }}" alt="" class="h-16 w-24 rounded-md object-cover" />
                                    @else
                                        <span class="flex h-16 w-24 items-center justify-center rounded-md bg-gray-100 text-2xl dark:bg-gray-700" aria-hidden="true">🖼️</span>
                                    @endif
                                    <div class="min-w-0 text-sm">
                                        <input type="file" accept="image/*" class="block w-full text-xs text-gray-600 dark:text-gray-300 file:mr-2 file:rounded-md file:border-0 file:bg-indigo-50 file:px-2 file:py-1 file:text-indigo-700"
                                            x-on:change="if ($event.target.files[0]) $dispatch('open-cropper', { target: '{{ $side === 'front' ? 'frontImage' : 'backImage' }}', file: $event.target.files[0] }); $event.target.value = ''" />
                                        <div wire:loading wire:target="{{ $side === 'front' ? 'frontImage' : 'backImage' }}" class="mt-1 text-xs text-gray-400">{{ __('Uploading …') }}</div>
                                        @if ($existingUrl)
                                            <label class="mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                <input type="checkbox" wire:model="{{ $removeFlag }}" class="rounded-sm border-gray-300 text-indigo-600" /> {{ __('Remove') }}
                                            </label>
                                        @endif
                                    </div>
                                </div>
                                <x-input-error :messages="$errors->get($side === 'front' ? 'frontImage' : 'backImage')" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Link to the online / app account --}}
                <div>
                    <x-input-label for="card-link" :value="__('Account link (online or app, optional)')" />
                    <x-text-input wire:model="linkUrl" id="card-link" type="url" inputmode="url" class="mt-1 block w-full" placeholder="https://…" />
                    <x-input-error :messages="$errors->get('linkUrl')" class="mt-1" />
                </div>

                {{-- Free labelled texts (personalise cards without a photo) --}}
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('More details (optional)') }}</span>
                        <button type="button" wire:click="addDetail" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">+ {{ __('Add text') }}</button>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-400">{{ __('e.g. customer number, member since, PIN hint – each with its own label.') }}</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($details as $index => $detail)
                            <div class="flex items-center gap-2" wire:key="detail-{{ $index }}">
                                <x-text-input wire:model="details.{{ $index }}.label" class="block w-1/3" placeholder="{{ __('Label') }}" />
                                <x-text-input wire:model="details.{{ $index }}.value" class="block flex-1" placeholder="{{ __('Text') }}" />
                                <button type="button" wire:click="removeDetail({{ $index }})" class="shrink-0 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <x-input-label for="card-notes" :value="__('Note (optional)')" />
                    <x-text-input wire:model="notes" id="card-notes" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <x-private-checkbox id="card-private" wire:model="private" />

                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    <button type="button" wire:click="$set('adding', false)" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Camera scanner overlay --}}
    <div
        x-show="visible"
        x-cloak
        class="fixed inset-0 z-[70] flex flex-col bg-black text-white"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Scan code') }}"
    >
        <div class="flex items-center justify-between px-4 py-3">
            <p class="font-medium">{{ __('Scan code') }}</p>
            <button type="button" x-on:click="close()" class="text-2xl" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-5 w-5" stroke-width="2" /></button>
        </div>
        <div class="relative flex-1 overflow-hidden">
            <video x-ref="video" x-show="supported && ! failed" playsinline webkit-playsinline muted autoplay class="h-full w-full object-cover"></video>
            <div x-show="supported && ! failed" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="h-40 w-72 rounded-lg border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"></div>
            </div>
            <p x-show="! supported || failed" class="p-6 text-center text-gray-300">{{ __('The camera could not be started. Allow camera access, upload an image, or type the number.') }}</p>
        </div>
        <div class="bg-gray-900 p-4 text-center">
            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-white/10 px-4 py-2 text-sm hover:bg-white/20">
                🖼️ {{ __('Upload image instead') }}
                <input type="file" accept="image/*" class="hidden" x-on:change="decodeFile($event)" />
            </label>
        </div>
    </div>

    {{-- Image cropper overlay (pan/zoom, canvas only – no library) --}}
    <div x-data="cardCropper({ uploadError: @js(__('The image could not be uploaded. Please try a smaller photo.')) })" wire:ignore>
        <div x-show="visible" x-cloak class="fixed inset-0 z-[80] flex flex-col items-center justify-center bg-black/80 p-4" role="dialog" aria-modal="true" aria-label="{{ __('Crop image') }}">
            <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Crop image') }}</h3>
                    <button type="button" x-on:click="close()" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-5 w-5" stroke-width="2" /></button>
                </div>

                <div class="flex justify-center">
                    <canvas x-ref="canvas"
                        class="touch-none cursor-grab rounded-lg ring-1 ring-gray-200 active:cursor-grabbing dark:ring-gray-700"
                        style="touch-action: none;"
                        x-on:pointerdown="onDown($event); $event.target.setPointerCapture?.($event.pointerId)"
                        x-on:pointermove="onMove($event)"
                        x-on:pointerup="onUp()"
                        x-on:pointercancel="onUp()"
                    ></canvas>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400">➖</span>
                    <input type="range" min="1" max="4" step="0.01" :value="zoomValue" x-on:input="zoomValue = $event.target.value; zoom(zoomValue)" class="flex-1 accent-indigo-600" aria-label="{{ __('Zoom') }}" />
                    <span class="text-xs text-gray-400">➕</span>
                </div>

                <div class="flex justify-center gap-2 text-sm">
                    <button type="button" x-on:click="setRatio(1.586)" :class="Math.abs(ratio - 1.586) < 0.01 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'" class="rounded-full px-3 py-1">{{ __('Card') }}</button>
                    <button type="button" x-on:click="setRatio(1)" :class="ratio === 1 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'" class="rounded-full px-3 py-1">{{ __('Square') }}</button>
                </div>

                <p class="text-center text-xs text-gray-400">{{ __('Drag to move, slider to zoom.') }}</p>

                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button type="button" x-on:click="confirm()" x-bind:disabled="busy"><span x-show="! busy">{{ __('Crop & use') }}</span><span x-show="busy" x-cloak>{{ __('Uploading …') }}</span></x-primary-button>
                    <button type="button" x-on:click="useOriginal()" x-bind:disabled="busy" class="text-sm text-gray-600 underline dark:text-gray-300">{{ __('Use original') }}</button>
                    <button type="button" x-on:click="close()" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
