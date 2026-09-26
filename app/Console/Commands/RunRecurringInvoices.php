<?php

namespace App\Console\Commands;

use App\Mail\BusinessDocumentMail;
use App\Models\BusinessProfile;
use App\Models\RecurringInvoice;
use App\Support\Business\MailTemplates;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('business:run-recurring')]
#[Description('Create the invoices of due recurring invoice templates, e-mail them when configured and notify the owner (runs daily via the scheduler)')]
class RunRecurringInvoices extends Command
{
    public function handle(WebPush $webPush): int
    {
        $created = 0;
        $sent = 0;

        RecurringInvoice::query()->where('active', true)->whereDate('next_run_on', '<=', today())->with('customer')
            ->each(function (RecurringInvoice $template) use (&$created, &$sent, $webPush) {
                $profile = BusinessProfile::where('user_id', $template->user_id)->first();

                if (! $profile) {
                    return;
                }

                // Catch up missed periods (e.g. the scheduler was down), but at most a year's worth.
                for ($i = 0; $i < 12 && $template->active && $template->next_run_on->lte(today()); $i++) {
                    $invoice = $template->run($profile);
                    $created++;
                    $emailed = false;

                    if ($template->auto_send && ! $invoice->isDraft() && $template->customer?->email) {
                        try {
                            $mail = MailTemplates::for($invoice, $profile);
                            Mail::to($template->customer->email)->send(new BusinessDocumentMail($invoice, $profile, $mail['subject'], $mail['body']));
                            $invoice->update(['sent_at' => now()]);
                            $emailed = true;
                            $sent++;
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }

                    $webPush->send(
                        $template->user,
                        '🔁 '.($invoice->isDraft() ? __('Recurring invoice draft created') : __('Invoice :number created', ['number' => $invoice->number])),
                        $template->name.($emailed ? ' – '.__('sent to :email', ['email' => $template->customer->email]) : ''),
                        route('business.index', ['document' => $invoice->id]),
                    );
                }
            });

        $this->info("Invoices created: {$created}, e-mailed: {$sent}");

        return self::SUCCESS;
    }
}
