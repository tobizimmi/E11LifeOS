@props(['model', 'method' => null, 'multiple' => false, 'label' => null])

{{--
    Drop files from the desktop onto this area: they are uploaded into the Livewire property
    $model (one after another, or all at once with $multiple) and $method is called after each
    upload. Only reacts to files, so sorting by drag & drop keeps working inside.
--}}
<div
    x-data="{
        over: false,
        depth: 0,
        hasFiles(event) { return [...(event.dataTransfer?.types ?? [])].includes('Files') },
        enter(event) { if (! this.hasFiles(event)) return; this.depth++; this.over = true },
        leave(event) { if (! this.hasFiles(event)) return; if (--this.depth <= 0) { this.depth = 0; this.over = false } },
        drop(event) {
            this.over = false; this.depth = 0;
            const files = [...(event.dataTransfer?.files ?? [])];
            if (files.length === 0) return;
            const method = @js($method);
            @if ($multiple)
                $wire.uploadMultiple(@js($model), files, () => { if (method) $wire.call(method) });
            @else
                const next = (index) => {
                    if (index >= files.length) return;
                    $wire.upload(@js($model), files[index], () => {
                        if (method) { $wire.call(method).then(() => next(index + 1)) } else { next(index + 1) }
                    });
                };
                next(0);
            @endif
        },
    }"
    x-on:dragenter="enter($event)"
    x-on:dragover="if (hasFiles($event)) $event.preventDefault()"
    x-on:dragleave="leave($event)"
    x-on:drop="if (hasFiles($event)) { $event.preventDefault(); drop($event) }"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    {{ $slot }}
    <div x-show="over" x-cloak class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center rounded-xl border-2 border-dashed border-indigo-400 bg-indigo-50/90 text-sm font-medium text-indigo-700 dark:bg-indigo-950/80 dark:text-indigo-200">
        📥 {{ $label ?? __('Drop files here') }}
    </div>
</div>
