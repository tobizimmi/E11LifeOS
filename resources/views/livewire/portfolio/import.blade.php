<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Support\AiException;
use App\Support\Portfolio\StatementAi;
use App\Support\Portfolio\TransactionImporter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;
    use WithFileUploads;

    public string $portfolioId = '';

    public string $broker = 'auto';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    /**
     * Parsed rows waiting for confirmation.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** @var list<string> */
    public array $errorsFound = [];

    public string $detectedBroker = '';

    /** @var array{imported: int, skipped: int}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->portfolioId = (string) ($this->portfolioSettings()->default_portfolio_id ?: $this->portfolios()->value('id'));
    }

    public function updatedFile(TransactionImporter $importer, StatementAi $ai): void
    {
        $this->reset('rows', 'errorsFound', 'detectedBroker', 'result');
        $this->validate(['file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,pdf,jpg,jpeg,png,webp,gif']], attributes: ['file' => __('File')]);

        $mime = (string) $this->file->getMimeType();
        $contents = (string) file_get_contents($this->file->getRealPath());

        if (in_array($mime, StatementAi::MEDIA_TYPES, true)) {
            if (! StatementAi::isConfigured()) {
                $this->addError('file', __('Reading PDFs and images needs the AI (ANTHROPIC_API_KEY). Please upload a CSV export.'));

                return;
            }

            try {
                $parsed = array_values(array_filter(array_map(fn (array $row) => $importer->normalize($row), $ai->read($contents, $mime))));
            } catch (AiException $exception) {
                $this->addError('file', $exception->getMessage());

                return;
            }

            $this->detectedBroker = 'ai';
            $this->rows = $parsed;
        } else {
            // Exports of German banks are often Windows-1252 encoded.
            if (! mb_check_encoding($contents, 'UTF-8')) {
                $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
            }

            $parsed = $importer->parse($contents, $this->broker);
            $this->detectedBroker = $parsed['broker'];
            $this->rows = array_slice($parsed['transactions'], 0, 5000);
            $this->errorsFound = $parsed['errors'];
        }

        if ($this->rows === []) {
            $this->addError('file', __('No transactions found in this file.').($this->errorsFound ? ' '.$this->errorsFound[0] : ''));
        }
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function import(TransactionImporter $importer): void
    {
        $this->validate(['portfolioId' => ['required', 'integer']], attributes: ['portfolioId' => __('Portfolio')]);
        abort_if($this->rows === [], 422);

        $portfolio = $this->findPortfolio((int) $this->portfolioId);
        $rows = array_values(array_filter(array_map(fn (array $row) => $importer->normalize($row), $this->rows)));
        $this->result = $importer->import($portfolio, $rows, $this->detectedBroker ?: 'import');
        $this->reset('rows', 'file', 'errorsFound');
    }

    public function with(): array
    {
        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'brokers' => TransactionImporter::BROKERS,
            'aiAvailable' => StatementAi::isConfigured(),
        ];
    }
}; ?>

@php
    $typeLabels = [
        'buy' => __('Purchase'), 'sell' => __('Sale'), 'dividend' => __('Dividend'), 'split' => __('Split'),
        'fee' => __('Fee'), 'transfer_in' => __('Transfer in'), 'transfer_out' => __('Transfer out'),
    ];
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.import'])

            @if ($portfolioList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                    {{ __('Create a portfolio first.') }} <a href="{{ route('portfolio.index') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-300">{{ __('Overview') }}</a>
                </div>
            @else
                @if ($result)
                    <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">
                        ✓ {{ trans_choice(':count transaction imported|:count transactions imported', $result['imported']) }}@if ($result['skipped']) · {{ trans_choice(':count skipped (already there or incomplete)|:count skipped (already there or incomplete)', $result['skipped']) }}@endif
                        <a href="{{ route('portfolio.index', ['depot' => $portfolioId]) }}" wire:navigate class="ms-2 font-medium underline">{{ __('Show portfolio') }}</a>
                    </p>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Import transactions') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Upload the CSV export of your broker (Trade Republic, DKB, ING, Smartbroker+, comdirect, Consorsbank, flatex, OnVista, Outbank, Finanzguru or any CSV).') }}
                        @if ($aiAvailable)
                            {{ __('PDF statements, trade confirmations and screenshots are read by the AI.') }}
                        @endif
                        {{ __('Transactions that already exist are skipped.') }}
                    </p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="import-portfolio" :value="__('Into portfolio')" />
                            <select id="import-portfolio" wire:model="portfolioId" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($portfolioList as $portfolio)
                                    <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="import-broker" :value="__('Format')" />
                            <select id="import-broker" wire:model="broker" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($brokers as $key => $label)
                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="import-file" :value="__('File')" />
                            <input id="import-file" type="file" wire:model="file" accept=".csv,.txt{{ $aiAvailable ? ',.pdf,image/*' : '' }}" class="mt-1 block w-full text-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-100">
                            <p wire:loading wire:target="file" class="mt-1 text-xs text-gray-500">{{ __('Reading file …') }}</p>
                            <x-input-error :messages="$errors->get('file')" class="mt-1" />
                        </div>
                    </div>
                </section>

                @if ($rows !== [])
                    <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ trans_choice(':count transaction found|:count transactions found', count($rows)) }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Format: :format', ['format' => $detectedBroker === 'ai' ? __('read by AI') : __($brokers[$detectedBroker] ?? $detectedBroker)]) }} · {{ __('ISIN and WKN are turned into symbols during the import.') }}</p>
                            </div>
                            <x-primary-button type="button" wire:click="import" wire:loading.attr="disabled">{{ __('Import') }}</x-primary-button>
                        </div>
                        @if ($errorsFound)
                            <details class="border-t border-gray-100 px-4 py-2 text-xs text-amber-800 dark:border-gray-700 dark:text-amber-200">
                                <summary>{{ trans_choice(':count line could not be read|:count lines could not be read', count($errorsFound)) }}</summary>
                                <ul class="mt-1 list-disc ps-5">
                                    @foreach ($errorsFound as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[40rem] text-sm">
                                <thead class="border-y border-gray-100 bg-gray-50 text-left text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">{{ __('Date') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Type') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Security') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Shares') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Price') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Fees') }}</th>
                                        <th class="w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach (array_slice($rows, 0, 200, true) as $index => $row)
                                        <tr wire:key="row-{{ $index }}-{{ $row['symbol'] }}-{{ $row['executed_on'] }}">
                                            <td class="px-4 py-2 tabular-nums">{{ \Illuminate\Support\Carbon::parse($row['executed_on'])->format('d.m.Y') }}</td>
                                            <td class="px-3 py-2">{{ $typeLabels[$row['type']] ?? $row['type'] }}</td>
                                            <td class="max-w-[16rem] px-3 py-2"><span class="block truncate">{{ $row['name'] ?: $row['symbol'] }}</span><span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['symbol'] }}</span></td>
                                            <td class="px-3 py-2 text-end tabular-nums">{{ $quantity($row['quantity']) }}</td>
                                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['price'], 2, ',', '.') }} {{ $row['currency'] }}</td>
                                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['fees'], 2, ',', '.') }}</td>
                                            <td class="pe-2"><button type="button" wire:click="removeRow({{ $index }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (count($rows) > 200)
                            <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('… and :count more', ['count' => count($rows) - 200]) }}</p>
                        @endif
                    </section>
                @endif
            @endif
        </div>
    </div>
</div>
