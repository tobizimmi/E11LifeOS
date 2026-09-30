@props(['expires' => null, 'action'])

{{-- Validity of a share link: unlimited or 7/30/90 days from now; $action is the Livewire method taking the days. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300']) }}>
    <label class="flex items-center gap-1">
        {{ __('Valid') }}
        <select x-on:change="$wire.{{ $action }}($event.target.value)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Link validity') }}">
            @if ($expires)
                <option value="keep" hidden selected>{{ __('change …') }}</option>
            @endif
            <option value="">{{ __('unlimited') }}</option>
            @foreach ([7, 30, 90] as $days)
                <option value="{{ $days }}">{{ trans_choice(':count day|:count days', $days) }}</option>
            @endforeach
        </select>
    </label>
    @if ($expires)
        <span @class(['text-red-600 dark:text-red-400' => $expires->isPast()])>{{ $expires->isPast() ? __('expired on :date', ['date' => $expires->format('d.m.Y')]) : __('until :date', ['date' => $expires->format('d.m.Y H:i')]) }}</span>
    @endif
</div>
