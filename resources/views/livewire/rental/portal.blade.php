<?php

use App\Models\RealEstateTenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Tenant portal: the tenant opens a long random link (no login) and finds the lease, the utility
 * statements that were sent, the handover protocols and the landlord's details.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->tenant();
    }

    private function tenant(): RealEstateTenant
    {
        return (strlen($this->token) >= 32 ? RealEstateTenant::whereShareToken('portal_token', $this->token)->with('realEstate')->first() : null) ?? abort(404);
    }

    public function with(): array
    {
        $tenant = $this->tenant();

        return [
            'tenant' => $tenant,
            'realEstate' => $tenant->realEstate,
            'statements' => $tenant->realEstate->utilityStatements()->whereNotNull('sent_on')->orderByDesc('year')->get(),
            'handovers' => $tenant->handovers()->get(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $link = 'flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-800';
@endphp

<div class="space-y-5">
    <div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">🔑 {{ __('Your flat') }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $realEstate->address() ?: $realEstate->name }}@if ($tenant->unit) · {{ $tenant->unit }}@endif</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $tenant->name }}</p>
    </div>

    <section class="space-y-1 text-sm">
        <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Rent') }}</h2>
        <p class="text-gray-700 dark:text-gray-300">{{ __(':cold cold + :advance advance = :total', ['cold' => $euro($tenant->cold_rent), 'advance' => $euro($tenant->utilities_advance), 'total' => $euro($tenant->monthlyTotal())]) }}</p>
        @if ($realEstate->landlord_iban)
            <p class="text-gray-700 dark:text-gray-300">{{ __('Please transfer to :iban by the :day. of each month.', ['iban' => $realEstate->landlord_iban, 'day' => $tenant->due_day ?: 3]) }}@if ($tenant->payment_reference) {{ __('Reference: :reference', ['reference' => $tenant->payment_reference]) }}@endif</p>
        @endif
    </section>

    <section class="space-y-2">
        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Documents') }}</h2>
        <a href="{{ route('rental.portal.lease', $token) }}" target="_blank" class="{{ $link }}"><span>📄 {{ __('Lease') }}</span><span class="text-gray-400">PDF</span></a>
        @foreach ($statements as $statement)
            <a wire:key="statement-{{ $statement->id }}" href="{{ route('rental.portal.statement', [$token, $statement->id]) }}" target="_blank" class="{{ $link }}"><span>🧮 {{ __('Utility statement :year', ['year' => $statement->year]) }}</span><span class="text-gray-400">PDF</span></a>
        @endforeach
        @foreach ($handovers as $handover)
            <a wire:key="handover-{{ $handover->id }}" href="{{ route('rental.portal.handover', [$token, $handover->id]) }}" target="_blank" class="{{ $link }}"><span>📋 {{ __('Handover protocol (:kind, :date)', ['kind' => \App\Models\RealEstateHandover::kindLabels()[$handover->kind] ?? $handover->kind, 'date' => $handover->handed_over_on->format('d.m.Y')]) }}</span><span class="text-gray-400">PDF</span></a>
        @endforeach
    </section>

    @if ($realEstate->landlord_name || $realEstate->landlord_address)
        <section class="text-sm">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Landlord') }}</h2>
            <p class="text-gray-700 dark:text-gray-300">{{ $realEstate->landlord_name }}@if ($realEstate->landlord_address)<br>{{ $realEstate->landlord_address }}@endif</p>
        </section>
    @endif
</div>
