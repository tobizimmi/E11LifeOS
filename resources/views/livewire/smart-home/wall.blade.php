<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\SmartHomeDashboard;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Wall tablet: one dashboard full screen with clock and date, the screen stays on (Wake Lock)
 * and dims at night. Values refresh live and every 30 seconds.
 */
new #[Layout('layouts.wall')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $dashboardId;

    public function mount(int $dashboard): void
    {
        $this->resolveHousehold();
        SmartHomeDashboard::query()->visibleTo(Auth::user(), $this->householdId)->find($dashboard) ?? abort(404);
        $this->dashboardId = $dashboard;
    }
}; ?>

<div class="min-h-full p-4 sm:p-6"
     x-data="{
        now: new Date(),
        dim: false,
        lock: null,
        tick() { this.now = new Date(); const hour = this.now.getHours(); this.dim = hour >= 22 || hour < 6; },
        async keepAwake() { try { this.lock = await navigator.wakeLock?.request('screen'); } catch (e) {} },
     }"
     x-init="tick(); setInterval(() => tick(), 15000); keepAwake(); document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && keepAwake())"
     :style="dim ? 'filter: brightness(0.45)' : ''"
     x-on:click="dim && (dim = false)">
    <header class="mb-4 flex items-end justify-between gap-4">
        <div>
            <p class="text-5xl font-semibold tracking-tight tabular-nums" x-text="now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></p>
            <p class="text-lg text-gray-600 dark:text-gray-400" x-text="now.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' })"></p>
        </div>
        <a href="{{ route('smart-home.index') }}" class="text-sm text-gray-500 hover:underline dark:text-gray-400">{{ __('Close') }}</a>
    </header>

    <livewire:smart-home.dashboard :dashboard-id="$dashboardId" :wall="true" />
</div>
