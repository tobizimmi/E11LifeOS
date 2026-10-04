<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\RecipeAi;
use App\Support\RecipeAiException;
use App\Support\RecipeImporter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    /**
     * "link", "photo", "pdf" or "text".
     */
    #[Url(except: 'link')]
    public string $source = 'link';

    #[Url(except: '')]
    public string $url = '';

    /**
     * @var array<int, mixed>
     */
    public array $photos = [];

    public $pdf = null;

    public string $text = '';

    /** Optional caption for Instagram links (Instagram itself often gives out a shortened one). */
    public string $caption = '';

    public ?string $failure = null;

    /**
     * Reads the chosen source and opens the recipe form pre-filled with the result.
     */
    public function import(RecipeImporter $importer): void
    {
        $this->failure = null;

        $this->validate(match ($this->source) {
            'photo' => ['photos' => ['required', 'array', 'min:1', 'max:'.RecipeAi::MAX_IMAGES], 'photos.*' => ['image', 'max:15360']],
            'pdf' => ['pdf' => ['required', 'file', 'mimes:pdf', 'max:20480']],
            'text' => ['text' => ['required', 'string', 'min:20', 'max:50000']],
            default => ['url' => ['required', 'url:http,https', 'max:2000'], 'caption' => ['nullable', 'string', 'max:20000']],
        });

        try {
            $draft = match ($this->source) {
                'photo' => $importer->fromImages($this->photos),
                'pdf' => $importer->fromPdf($this->pdf),
                'text' => $importer->fromText($this->text),
                default => $importer->fromUrl(trim($this->url), trim($this->caption) ?: null),
            };
        } catch (RecipeAiException $exception) {
            $this->failure = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->failure = __('The page could not be loaded. Check the link or paste the recipe text instead.');

            return;
        }

        $draft['imagePath'] = ! empty($draft['imageUrl']) ? $importer->downloadImage($draft['imageUrl'], (int) $this->householdId) : null;

        session()->put('recipe_draft', $draft);
        $this->redirectRoute('recipes.create', navigate: true);
    }

    public function with(): array
    {
        return [
            'aiAvailable' => RecipeAi::isConfigured(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Import recipe') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">&larr; {{ __('All recipes') }}</a>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Import from') }}">
                @foreach (['link' => '🔗 '.__('Link'), 'photo' => '📷 '.__('Photo / screenshot'), 'pdf' => '📄 '.__('PDF'), 'text' => '📝 '.__('Text')] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $source === $key ? 'true' : 'false' }}" wire:click="$set('source', '{{ $key }}')" @class(['rounded-full px-4 py-1.5 text-sm', 'bg-indigo-600 text-white' => $source === $key, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700' => $source !== $key])>{{ $label }}</button>
                @endforeach
            </div>

            <form wire:submit="import" class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-4">
                @if ($source === 'link')
                    <div>
                        <x-input-label for="url" :value="__('Link to the recipe')" />
                        <x-text-input wire:model="url" id="url" type="url" class="mt-1 block w-full" placeholder="https://www.chefkoch.de/rezepte/…" autofocus />
                        <x-input-error :messages="$errors->get('url')" class="mt-1" />
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Most recipe websites provide structured data that is read directly.') }}
                            @if ($aiAvailable)
                                {{ __('Otherwise the AI reads the page.') }}
                            @endif
                            @if ($aiAvailable)
                                {{ __('Instagram posts and reels work too: the AI reads the recipe from the post text.') }}
                            @endif
                        </p>
                    </div>
                    <div x-data x-show="($wire.url || '').toLowerCase().includes('instagr')" x-cloak>
                        <x-input-label for="caption" :value="__('Post text (optional)')" />
                        <textarea wire:model="caption" id="caption" rows="5" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs" placeholder="{{ __('In Instagram: tap “…” › “Copy” or hold the post text, copy it and paste it here.') }}"></textarea>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">💡 {{ __('Without the text, amounts are often missing, because Instagram shortens it.') }}</p>
                        <x-input-error :messages="$errors->get('caption')" class="mt-1" />
                    </div>
                @elseif (! $aiAvailable)
                    <p class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200">{{ __('Importing photos, PDFs and text needs the AI, which is not set up on this server (ANTHROPIC_API_KEY).') }}</p>
                @elseif ($source === 'photo')
                    <div>
                        <x-input-label for="photos" :value="__('Photos or screenshots of the recipe')" />
                        <input type="file" wire:model="photos" id="photos" accept="image/*" multiple class="mt-1 block text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-indigo-700" />
                        <p wire:loading wire:target="photos" class="mt-1 text-xs text-gray-500">{{ __('Uploading …') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($photos as $index => $photo)
                                @if (method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                    <img wire:key="import-photo-{{ $index }}" src="{{ $photo->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-md object-cover" />
                                @endif
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Up to :count images, e.g. several cookbook pages. Handwritten recipes work too.', ['count' => \App\Support\RecipeAi::MAX_IMAGES]) }}</p>
                        <x-input-error :messages="array_merge($errors->get('photos'), $errors->get('photos.*'))" class="mt-1" />
                    </div>
                @elseif ($source === 'pdf')
                    <div>
                        <x-input-label for="pdf" :value="__('PDF with the recipe')" />
                        <input type="file" wire:model="pdf" id="pdf" accept="application/pdf" class="mt-1 block text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-indigo-700" />
                        <p wire:loading wire:target="pdf" class="mt-1 text-xs text-gray-500">{{ __('Uploading …') }}</p>
                        <x-input-error :messages="$errors->get('pdf')" class="mt-1" />
                    </div>
                @else
                    <div>
                        <x-input-label for="text" :value="__('Recipe text')" />
                        <textarea wire:model="text" id="text" rows="10" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs" placeholder="{{ __('Paste the recipe, e.g. from an e-mail, a chat or an Instagram post.') }}"></textarea>
                        <x-input-error :messages="$errors->get('text')" class="mt-1" />
                    </div>
                @endif

                @if ($failure)
                    <p class="rounded-md bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $failure }}</p>
                @endif

                @if ($source === 'link' || $aiAvailable)
                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button wire:loading.attr="disabled" wire:target="import,photos,pdf">{{ __('Import') }}</x-primary-button>
                        <span wire:loading wire:target="import" class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reading the recipe … this can take a moment.') }}</span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('You can check and correct everything before saving.') }}
                        @if ($aiAvailable)
                            {{ __('Photos, PDFs, text and pages without recipe data are sent to the Claude API (Anthropic) for reading.') }}
                        @endif
                    </p>
                @endif
            </form>
        </div>
    </div>
</div>
