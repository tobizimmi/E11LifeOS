@props(['for', 'submit' => false])

{{--
    Microphone button for dictation into the input with id $for (browser speech recognition,
    e.g. Chrome/Android, Safari on iPhone/Mac, Edge). Hidden where the browser has none.
    With $submit the surrounding form is sent when you stop speaking.
--}}
<button
    type="button"
    x-data="voiceInput(@js($for), @js((bool) $submit))"
    x-show="supported"
    x-cloak
    x-on:click="toggle()"
    :aria-pressed="listening"
    :class="listening ? 'bg-red-600 text-white animate-pulse ring-red-600' : 'text-gray-600 dark:text-gray-300 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
    {{ $attributes->merge(['class' => 'shrink-0 rounded-md px-3 ring-1 text-lg']) }}
    :title="listening ? @js(__('Stop dictation')) : @js(__('Dictate'))"
    :aria-label="listening ? @js(__('Stop dictation')) : @js(__('Dictate'))"
>🎤</button>
