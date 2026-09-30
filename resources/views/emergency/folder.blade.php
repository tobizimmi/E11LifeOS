{{-- Read-only emergency folder (for trusted people): $entries, $sections, $overview --}}
<div class="space-y-5">
    @foreach ($sections as $key => $info)
        @php
            $sectionEntries = $entries->where('section', $key);
        @endphp
        @if ($sectionEntries->isNotEmpty())
            <section>
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $info['icon'] }} {{ $info['label'] }}</h2>
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach ($sectionEntries as $item)
                        <li class="rounded-lg p-3 ring-1 ring-gray-200 dark:ring-gray-700">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</p>
                            @if ($item->details)
                                <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $item->details }}</p>
                            @endif
                            @if ($item->location)
                                <p class="mt-1 text-gray-600 dark:text-gray-300">📍 {{ $item->location }}</p>
                            @endif
                            @if ($item->expires_on)
                                <p class="mt-1 text-gray-500 dark:text-gray-400">{{ __('valid until :date', ['date' => $item->expires_on->format('d.m.Y')]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach

    @foreach ($overview as $group)
        <section>
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $group['icon'] }} {{ $group['label'] }}</h2>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($group['rows'] as $row)
                    <li class="flex flex-wrap justify-between gap-x-3 py-1.5">
                        <span class="text-gray-800 dark:text-gray-100">{{ $row['label'] }}</span>
                        @if ($row['detail'])
                            <span class="text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
