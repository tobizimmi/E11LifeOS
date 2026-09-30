<?php

use App\Support\ContractOverview;
use App\Support\LetterTemplates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Letter templates (cancellation, objection, change of address …), filled from a contract entry and
 * downloaded as PDF.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $template = LetterTemplates::CANCELLATION;

    public string $sender = '';

    public string $recipient = '';

    public string $place = '';

    public string $subject = '';

    public string $body = '';

    public bool $rememberAddress = true;

    #[Url(as: 'entry')]
    public ?int $entryId = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->template = array_key_exists($this->template, LetterTemplates::all()) ? $this->template : LetterTemplates::CANCELLATION;
        $this->sender = (string) ($user->postal_address ?: $user->name);
        $this->place = (string) ($user->weather_place ?? '');
        $this->fillTemplate();
    }

    /**
     * Switches the template and refills subject and text.
     */
    public function chooseTemplate(string $template): void
    {
        abort_unless(array_key_exists($template, LetterTemplates::all()), 404);
        $this->template = $template;
        $this->fillTemplate();
    }

    private function fillTemplate(): void
    {
        $contract = $this->contract();

        if ($contract && trim($this->recipient) === '') {
            $this->recipient = (string) ($contract['provider'] ?? '');
        }

        $filled = LetterTemplates::fill($this->template, $contract ? [
            'contract' => $contract['item']->title,
            'provider' => $contract['provider'],
            'customer_number' => $contract['customer_number'],
            'ends_on' => $contract['ends_on'],
        ] : []);
        $this->subject = $filled['subject'];
        $this->body = $filled['body'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contract(): ?array
    {
        if (! $this->entryId) {
            return null;
        }

        $household = Auth::user()->currentHousehold();

        return $household
            ? collect(ContractOverview::for(Auth::user(), $household->id)['contracts'])->first(fn (array $contract) => $contract['item']->id === $this->entryId)
            : null;
    }

    public function download(): StreamedResponse
    {
        $validated = $this->validate([
            'sender' => ['required', 'string', 'max:500'],
            'recipient' => ['required', 'string', 'max:500'],
            'place' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $user = Auth::user();

        if ($this->rememberAddress) {
            $user->update(['postal_address' => trim($this->sender)]);
        }

        $pdf = LetterTemplates::pdf($user, $validated + ['place' => (string) $this->place]);

        return response()->streamDownload(fn () => print ($pdf), Str::slug(Str::limit($this->subject, 60, '')) .'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function with(): array
    {
        return ['templates' => LetterTemplates::all(), 'contract' => $this->contract()];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Letters') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Template') }}">
                @foreach ($templates as $key => $definition)
                    <button type="button" wire:click="chooseTemplate('{{ $key }}')" aria-pressed="{{ $template === $key ? 'true' : 'false' }}" @class(['rounded-full px-3 py-1.5 text-sm ring-1', 'bg-indigo-600 text-white ring-indigo-600' => $template === $key, 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $template !== $key])>{{ $definition['icon'] }} {{ $definition['name'] }}</button>
                @endforeach
            </div>

            @if ($contract)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Filled in from :contract.', ['contract' => $contract['item']->title]) }} <a href="{{ $contract['url'] }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Open entry') }}</a></p>
            @endif

            <form wire:submit="download" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="letter-sender" :value="__('Your name and address')" />
                        <textarea id="letter-sender" wire:model="sender" rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="{{ __('Name, street, postcode and town – one per line') }}"></textarea>
                        <x-input-error :messages="$errors->get('sender')" class="mt-1" />
                        <label class="mt-1 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model="rememberAddress" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900"> {{ __('Remember for the next letter') }}</label>
                    </div>
                    <div>
                        <x-input-label for="letter-recipient" :value="__('Recipient')" />
                        <textarea id="letter-recipient" wire:model="recipient" rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea>
                        <x-input-error :messages="$errors->get('recipient')" class="mt-1" />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="letter-place" :value="__('Place')" />
                        <x-text-input id="letter-place" wire:model="place" type="text" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="letter-subject" :value="__('Subject')" />
                        <x-text-input id="letter-subject" wire:model="subject" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label for="letter-body" :value="__('Text')" />
                    <textarea id="letter-body" wire:model="body" rows="12" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea>
                    <x-input-error :messages="$errors->get('body')" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Replace the parts in [brackets]. Send cancellations by registered mail or with proof of delivery.') }}</p>
                </div>
                <x-primary-button>⬇️ {{ __('Download PDF') }}</x-primary-button>
            </form>
        </div>
    </div>
</div>
