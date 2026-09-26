<?php

namespace App\Console\Commands;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('business:overdue-notices')]
#[Description('Notify business owners about invoices that became overdue yesterday or whose reminder deadline ran out (runs daily via the scheduler)')]
class SendOverdueInvoiceNotices extends Command
{
    public function handle(WebPush $webPush): int
    {
        $notified = 0;
        $yesterday = today()->subDay();

        Invoice::query()->where('type', Invoice::INVOICE)->where('status', Invoice::OPEN)->whereNotNull('due_on')
            ->with('user')->get()
            ->filter(function (Invoice $invoice) use ($yesterday) {
                if ($invoice->reminder_level === 0) {
                    return $invoice->due_on->isSameDay($yesterday);
                }

                $days = BusinessProfile::where('user_id', $invoice->user_id)->value('reminder_days') ?? 7;

                return $invoice->reminder_level < 3 && $invoice->last_reminder_at?->copy()->addDays($days)->isSameDay($yesterday);
            })
            ->groupBy('user_id')
            ->each(function ($invoices) use (&$notified, $webPush) {
                $first = $invoices->first();
                $body = $invoices->take(4)->map(fn (Invoice $invoice) => '• '.$invoice->number.' – '.(strtok($invoice->recipient, "\n") ?: '').' ('.number_format($invoice->outstanding(), 2, ',', '.').' €)')->implode("\n");

                $webPush->send(
                    $first->user,
                    '⏰ '.trans_choice(':count invoice is overdue|:count invoices are overdue', $invoices->count()),
                    $body,
                    $invoices->count() === 1 ? route('business.index', ['document' => $first->id]) : route('business.index', ['status' => 'overdue']),
                );
                $notified += $invoices->count();
            });

        $this->info("Overdue invoices notified: {$notified}");

        return self::SUCCESS;
    }
}
