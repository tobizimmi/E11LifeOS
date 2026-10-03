<?php

use App\Support\AppVersion;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * What changed: the releases from CHANGELOG.md with the version and build that is running.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'releases' => AppVersion::releases(),
            'version' => AppVersion::version(),
            'build' => AppVersion::build(),
        ];
    }
}; ?>

@php
    $sectionIcons = ['Neu' => '✨', 'Verbessert' => '🛠️', 'Behoben' => '🐞', 'Sicherheit' => '🔒'];
@endphp

<div x-data x-init="try { localStorage.setItem('e11-seen-version', @js($version)) } catch (e) {}; $dispatch('version-seen')">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('What\'s new') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Installed version') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">v{{ $version }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($build['build'] !== null)
                        {{ __('Build :number', ['number' => $build['build']]) }}
                        @if ($build['commit']) · {{ $build['commit'] }} @endif
                        @if ($build['date']) · {{ __('deployed :time', ['time' => \Illuminate\Support\Carbon::parse($build['date'])->diffForHumans()]) }} @endif
                        · {{ $build['environment'] === 'live' ? 'Live' : 'Staging' }}
                    @else
                        {{ __('Local installation (no deploy)') }}
                    @endif
                </p>
            </section>

            @foreach ($releases as $release)
                <article wire:key="release-{{ $release['version'] }}" class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="flex flex-wrap items-baseline gap-2 font-semibold text-gray-900 dark:text-gray-100">
                        <span>v{{ $release['version'] }}</span>
                        @if ($release['date'])<span class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ $release['date'] }}</span>@endif
                        @if ($loop->first)<span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">{{ __('current') }}</span>@endif
                    </h3>
                    @foreach ($release['sections'] as $section)
                        @if ($section['title'] !== '')
                            <h4 class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $sectionIcons[$section['title']] ?? '•' }} {{ $section['title'] }}</h4>
                        @endif
                        <ul class="mt-1 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($section['items'] as $item)
                                {{-- Only `code` is rendered from the Markdown; everything else is escaped. --}}
                                <li>{!! preg_replace('/`([^`]+)`/', '<code class="rounded-sm bg-gray-100 px-1 text-xs dark:bg-gray-700">$1</code>', e($item)) !!}</li>
                            @endforeach
                        </ul>
                    @endforeach
                </article>
            @endforeach
        </div>
    </div>
</div>
