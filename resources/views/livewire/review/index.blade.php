<?php

use App\Support\Life\YearReview;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The year in review: money, everyday life and the car at a glance.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        if ($this->year < 2000 || $this->year > (int) now()->year) {
            $this->year = (int) now()->year;
        }
    }

    public function with(): array
    {
        return [
            'review' => YearReview::for(Auth::user(), $this->year),
            'years' => range((int) now()->year, max(2020, (int) Auth::user()->created_at->year - 1)),
        ];
    }
}; ?>

@php
    $euro = fn (float $value) => number_format($value, 0, ',', '.').' €';
    $tile = 'rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800';
    $label = 'text-sm text-gray-500 dark:text-gray-400';
    $value = 'mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🎉 {{ __('Year in review') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $review['year'] }}</p>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Year') }}
                    <select wire:model.live="year" class="ms-2 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        @foreach ($years as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($review['finance'])
                <h3 class="pt-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">💶 {{ __('Money') }}</h3>
                <section class="grid gap-3 sm:grid-cols-4">
                    <div class="{{ $tile }}"><p class="{{ $label }}">{{ __('Income (statement)') }}</p><p class="{{ $value }}">{{ $euro($review['finance']['income']) }}</p></div>
                    <div class="{{ $tile }}"><p class="{{ $label }}">{{ __('Expenses') }}</p><p class="{{ $value }}">{{ $euro($review['finance']['expenses']) }}</p></div>
                    <div class="{{ $tile }}"><p class="{{ $label }}">{{ __('Left over') }}</p><p @class([$value, '!text-emerald-700 dark:!text-emerald-400' => $review['finance']['saved'] >= 0, '!text-rose-700 dark:!text-rose-400' => $review['finance']['saved'] < 0])>{{ $euro($review['finance']['saved']) }}</p></div>
                    <div class="{{ $tile }}"><p class="{{ $label }}">{{ __('Savings rate') }}</p><p class="{{ $value }}">{{ $review['finance']['rate'] !== null ? number_format($review['finance']['rate'], 1, ',', '.').' %' : '–' }}</p></div>
                </section>
                <section class="grid gap-3 md:grid-cols-2">
                    <div class="{{ $tile }}">
                        <p class="{{ $label }}">{{ __('Biggest expenses') }}</p>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach ($review['finance']['categories'] as $category)
                                <li class="flex justify-between gap-3"><span class="text-gray-700 dark:text-gray-200">{{ $category['icon'] }} {{ $category['label'] }}</span><span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($category['amount']) }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="{{ $tile }} space-y-3">
                        @if ($review['finance']['best_month'])
                            <p class="text-sm text-gray-700 dark:text-gray-200">🏆 {{ __('Best month: :month', ['month' => $review['finance']['best_month']]) }}</p>
                        @endif
                        @if ($review['net_worth'])
                            <p class="text-sm text-gray-700 dark:text-gray-200">💎 {{ __('Net worth :change (from :start to :end)', ['change' => ($review['net_worth']['change'] >= 0 ? '+' : '').$euro($review['net_worth']['change']), 'start' => $euro($review['net_worth']['start']), 'end' => $euro($review['net_worth']['end'])]) }}</p>
                        @endif
                        @if ($review['dividends'])
                            <p class="text-sm text-gray-700 dark:text-gray-200">💰 {{ __(':amount dividends received', ['amount' => $euro($review['dividends'])]) }}</p>
                        @endif
                    </div>
                </section>
            @endif

            <h3 class="pt-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">🌿 {{ __('Everyday life') }}</h3>
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="{{ $tile }}"><p class="{{ $label }}">✅ {{ __('Tasks done') }}</p><p class="{{ $value }}">{{ number_format($review['tasks'], 0, ',', '.') }}</p></div>
                <div class="{{ $tile }}"><p class="{{ $label }}">📝 {{ __('Tasks & entries created') }}</p><p class="{{ $value }}">{{ number_format($review['planned'], 0, ',', '.') }}</p></div>
                <div class="{{ $tile }}"><p class="{{ $label }}">📄 {{ __('Pages written') }}</p><p class="{{ $value }}">{{ number_format($review['pages'], 0, ',', '.') }}</p></div>
                <div class="{{ $tile }}"><p class="{{ $label }}">🛒 {{ __('Shopping items') }}</p><p class="{{ $value }}">{{ number_format($review['shopping'], 0, ',', '.') }}</p></div>
                <div class="{{ $tile }}"><p class="{{ $label }}">🍳 {{ __('Meals planned') }}</p><p class="{{ $value }}">{{ number_format($review['meals'], 0, ',', '.') }}</p></div>
                <div class="{{ $tile }}"><p class="{{ $label }}">⭐ {{ __('Recipes rated') }}</p><p class="{{ $value }}">{{ number_format($review['cooked_ratings'], 0, ',', '.') }}</p></div>
                @if ($review['km'] !== null)
                    <div class="{{ $tile }}"><p class="{{ $label }}">🚗 {{ __('Kilometres driven') }}</p><p class="{{ $value }}">{{ number_format($review['km'], 0, ',', '.') }}</p></div>
                    <div class="{{ $tile }}"><p class="{{ $label }}">⛽ {{ __('Vehicle costs') }}</p><p class="{{ $value }}">{{ $euro($review['vehicle_costs']) }}</p></div>
                @endif
            </section>
            @if ($review['top_recipe'])
                <p class="text-sm text-gray-700 dark:text-gray-200">❤️ {{ __('Favourite dish of the year: :recipe', ['recipe' => $review['top_recipe']]) }}</p>
            @endif
        </div>
    </div>
</div>
