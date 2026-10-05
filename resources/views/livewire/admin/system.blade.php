<?php

use App\Support\Portfolio\InstagramAi;
use App\Support\Security\VirusScanner;
use App\Support\StructuredAi;
use App\Support\Vpn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin › System: health checks, slow pages, status of the services, recent errors and failed
 * background jobs.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
    }

    /**
     * Puts all failed background jobs back into the queue.
     */
    public function retryFailedJobs(): void
    {
        $this->authorize('admin');
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->dispatch('toast', message: __('Failed jobs were queued again.'));
    }

    /**
     * Runs the restore test of the latest backup right away.
     */
    public function verifyBackup(): void
    {
        $this->authorize('admin');
        $passed = Artisan::call('backup:verify') === 0;
        $this->dispatch('toast', message: $passed ? __('Restore test passed.') : __('Restore test failed – see the details below.'));
    }

    public function deleteFailedJobs(): void
    {
        $this->authorize('admin');
        Artisan::call('queue:flush');
        $this->dispatch('toast', message: __('Failed jobs were deleted.'));
    }

    /**
     * The latest failed jobs with the job name and the first line of the error.
     *
     * @return list<array{job: string, queue: string, failed_at: string, error: string}>
     */
    private function recentFailedJobs(): array
    {
        return rescue(fn () => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get()
            ->map(fn (object $job) => [
                'job' => class_basename((string) (json_decode($job->payload, true)['displayName'] ?? '?')),
                'queue' => (string) $job->queue,
                'failed_at' => (string) $job->failed_at,
                'error' => \Illuminate\Support\Str::limit(strtok((string) $job->exception, "\n"), 300),
            ])->all(), [], false);
    }

    public function with(): array
    {
        $heartbeat = Cache::get('scheduler:heartbeat');

        return [
            'heartbeat' => $heartbeat ? Carbon::parse($heartbeat) : null,
            'failedJobs' => rescue(fn () => DB::table('failed_jobs')->count(), 0, false),
            'recentFailedJobs' => $this->recentFailedJobs(),
            'recentErrors' => Cache::get(\App\Support\ErrorReporter::CACHE_KEY, []),
            'health' => \App\Support\Operations\SystemHealth::checks(),
            'restoreTest' => Cache::get(\App\Support\Operations\SystemHealth::VERIFY_KEY),
            'slowRequests' => array_slice(Cache::get(\App\Http\Middleware\RecordSlowRequests::SLOW_KEY, []), 0, 10),
            'slowRoutes' => \App\Http\Middleware\RecordSlowRequests::slowestRoutes(),
            'remoteBackup' => Cache::get('backup:remote-last'),
            'status' => [
                __('AI (Claude)') => StructuredAi::isConfigured(),
                __('Sending e-mails') => config('mail.default') !== 'log' && config('mail.default') !== 'array',
                __('Web push') => filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key')),
                __('Bank sync (FinTS)') => filled(config('services.fints.product_id')),
                __('Instagram analysis') => filled(config('services.instagram.apify_key')) && InstagramAi::isConfigured(),
                __('Encrypted backup off the server') => filled(config('backup.remote_url')) && filled(config('backup.encryption_password')),
                __('Error reports by e-mail') => filled(config('security.error_report_email')),
                __('Two-factor authentication required') => (bool) config('security.require_two_factor'),
                __('Virus scan of uploads') => VirusScanner::isEnabled(),
                __('VPN connected') => Vpn::isRunning(),
            ],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('System health') }}</h3>
                    <button type="button" wire:click="verifyBackup" wire:loading.attr="disabled" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Run restore test now') }}</button>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Checked every ten minutes – admins get a push when something turns red and when it is fine again.') }}</p>
                <ul class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    @foreach ($health as $key => $check)
                        <li class="flex items-start gap-2" wire:key="health-{{ $key }}">
                            <span @class(['text-emerald-600' => $check['ok'], 'text-red-600' => ! $check['ok']]) aria-hidden="true">{{ $check['ok'] ? '✓' : '✗' }}</span>
                            <span><span class="text-gray-900 dark:text-gray-100">{{ $check['label'] }}</span><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</span></span>
                        </li>
                    @endforeach
                </ul>
                @if (is_array($restoreTest) && ($restoreTest['problems'] ?? []) !== [])
                    <ul class="mt-3 space-y-1 rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200">
                        @foreach ($restoreTest['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($slowRoutes !== [] || $slowRequests !== [])
                    <details class="mt-3 text-xs">
                        <summary class="cursor-pointer text-gray-700 dark:text-gray-300">{{ __('Slow pages (this week)') }}</summary>
                        @if ($slowRoutes !== [])
                            <table class="mt-2 w-full text-start">
                                <thead class="text-gray-500"><tr><th class="py-1 text-start font-normal">{{ __('Page') }}</th><th class="text-end font-normal">{{ __('Requests') }}</th><th class="text-end font-normal">⌀ ms</th><th class="text-end font-normal">max ms</th></tr></thead>
                                <tbody>
                                    @foreach ($slowRoutes as $row)
                                        <tr class="border-t border-gray-100 dark:border-gray-700"><td class="py-1 font-mono">{{ $row['route'] }}</td><td class="text-end tabular-nums">{{ $row['count'] }}</td><td @class(['text-end tabular-nums', 'text-orange-600' => $row['average'] >= 300])>{{ $row['average'] }}</td><td class="text-end tabular-nums">{{ $row['max'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        @if ($slowRequests !== [])
                            <p class="mt-3 font-medium text-gray-700 dark:text-gray-300">{{ __('Requests over one second') }}</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($slowRequests as $request)
                                    <li class="rounded-sm bg-gray-50 p-1.5 dark:bg-gray-900"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($request['at'])->format('d.m. H:i') }}</span> <span class="font-mono">{{ $request['route'] }}</span> · {{ $request['ms'] }} ms · {{ trans_choice(':count query|:count queries', $request['queries']) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </details>
                @endif
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Status') }}</h3>
                <ul class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <li class="flex items-center gap-2">
                        @if ($heartbeat && $heartbeat->gt(now()->subMinutes(5)))
                            <span class="text-emerald-600">✓</span> {{ __('Scheduler (cron) runs') }} <span class="text-xs text-gray-500">({{ $heartbeat->diffForHumans() }})</span>
                        @else
                            <span class="text-orange-600">✗</span> <span>{{ __('Scheduler (cron) does not run') }} <span class="block text-xs text-gray-500">{{ __('Reminders, prices, news, backups and imports need the scheduler. It is set up automatically as PM2 process on the next deploy (no cron job in Plesk needed).') }}</span></span>
                        @endif
                    </li>
                    @foreach ($status as $label => $ready)
                        <li class="flex items-center gap-2"><span @class(['text-emerald-600' => $ready, 'text-gray-400' => ! $ready])>{{ $ready ? '✓' : '–' }}</span> {{ $label }}</li>
                    @endforeach
                </ul>
                @if ($remoteBackup)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Last copy off the server: :date', ['date' => \Illuminate\Support\Carbon::parse($remoteBackup)->diffForHumans()]) }}</p>
                @endif
                @if ($recentErrors !== [])
                    <details class="mt-3 text-xs">
                        <summary class="cursor-pointer text-orange-700 dark:text-orange-400">{{ trans_choice(':count recent error|:count recent errors', count($recentErrors)) }}</summary>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($recentErrors as $error)
                                <li class="rounded-sm bg-gray-50 p-2 dark:bg-gray-900"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($error['at'])->format('d.m. H:i') }}</span> <strong>{{ $error['type'] }}</strong>: {{ $error['message'] }}<br><span class="text-gray-500">{{ $error['where'] }} · {{ $error['url'] }}</span></li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if ($failedJobs > 0)
                    <details class="mt-3 text-xs">
                        <summary class="cursor-pointer text-orange-700 dark:text-orange-400">{{ trans_choice(':count background job failed.|:count background jobs failed.', $failedJobs) }}</summary>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($recentFailedJobs as $job)
                                <li class="rounded-sm bg-gray-50 p-2 dark:bg-gray-900"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($job['failed_at'])->format('d.m. H:i') }}</span> <strong>{{ $job['job'] }}</strong> <span class="text-gray-500">({{ $job['queue'] }})</span><br>{{ $job['error'] }}</li>
                            @endforeach
                        </ul>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <x-secondary-button type="button" wire:click="retryFailedJobs">↻ {{ __('Retry all') }}</x-secondary-button>
                            <x-secondary-button type="button" wire:click="deleteFailedJobs" wire:confirm="{{ __('Delete all failed jobs?') }}">🗑 {{ __('Delete all') }}</x-secondary-button>
                        </div>
                    </details>
                @endif
            </section>

        </div>
    </div>
</div>
