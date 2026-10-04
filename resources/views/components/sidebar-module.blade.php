@props(['key', 'module', 'badge' => null, 'active' => false, 'pinned' => false])

<x-sidebar-link :icon="$module['icon']" :href="route($module['route'])" :active="$active" :badge="$badge ?: null" wire:navigate {{ $attributes }}>{{ $module['label'] }}</x-sidebar-link>
