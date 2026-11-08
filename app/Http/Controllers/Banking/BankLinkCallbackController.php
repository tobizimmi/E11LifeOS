<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankLink;
use App\Support\Banking\BankLinkSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the bank sends the user back after granting (or declining) the PSD2 consent. Completes the
 * connection and runs a first sync, then returns to the banking page with a status message.
 */
class BankLinkCallbackController extends Controller
{
    public function __invoke(Request $request, BankLinkSync $sync): RedirectResponse
    {
        // GoCardless echoes our reference as "ref"; Enable Banking echoes it as "state".
        $reference = (string) ($request->query('ref') ?: $request->query('state'));

        $link = BankLink::where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->latest('id')
            ->first();

        if (! $link) {
            return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('The bank connection could not be found.'));
        }

        try {
            if (! $sync->complete($link, (string) $request->query('code') ?: null)) {
                // Not an exception, but worth a trace so it shows under Admin › System.
                Log::warning('Bank link not confirmed on callback', [
                    'link_id' => $link->id,
                    'provider' => $link->provider,
                    'has_code' => $request->query('code') !== null,
                    'bank' => $link->institution_name,
                ]);

                return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('The bank did not confirm the connection.'));
            }

            $result = $sync->sync($link);

            return redirect()->route('portfolio.banking')->with('bankLinkStatus', trim(
                __(':bank connected.', ['bank' => $link->institution_name]).' '.
                trans_choice(':count booking imported into Finances.|:count bookings imported into Finances.', $result['imported'])
            ));
        } catch (Throwable $exception) {
            // Log it so the real reason shows under Admin › System instead of being swallowed.
            report($exception);

            return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('The bank connection was set up, but the first sync failed. Please try "Sync now".'));
        }
    }
}
