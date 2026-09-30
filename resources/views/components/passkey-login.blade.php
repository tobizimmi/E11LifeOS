@props(['label' => null])

{{-- Button "Sign in with a passkey" (Alpine component passkeyLogin in app.js); used on the login and second-step page. --}}
<div x-data="passkeyLogin" x-show="supported" x-cloak
    data-options-url="{{ route('passkeys.options') }}"
    data-login-url="{{ route('passkeys.login') }}"
    data-cancelled="{{ __('Cancelled.') }}"
    data-failed="{{ __('This passkey could not be verified. Please try again or sign in with your password.') }}"
    {{ $attributes->merge(['class' => 'space-y-2']) }}>
    <button type="button" x-on:click="signIn" x-bind:disabled="busy" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700">
        <span aria-hidden="true">🔑</span> {{ $label ?? __('Sign in with a passkey') }}
    </button>
    <p x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400" role="alert"></p>
</div>
