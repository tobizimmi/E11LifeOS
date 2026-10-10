<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Support\Banking\BankLinkSync;
use App\Support\Banking\GoCardlessClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Starts a PSD2 consent for the chosen bank and sends the user to the bank's hosted login. A normal
 * request (not Livewire) so the redirect to the external bank page works cleanly.
 */
class BankLinkConnectController extends Controller
{
    public function __invoke(Request $request, GoCardlessClient $client, BankLinkSync $sync): RedirectResponse
    {
        abort_unless($client->configured(), 404);

        $institutionId = (string) $request->query('institution');
        $institution = $institutionId !== '' ? $client->institution($institutionId) : null;

        if (! $institution) {
            return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('Please choose a bank.'));
        }

        try {
            $link = $sync->connect(
                $request->user(),
                $institution['id'],
                $institution['name'],
                $institution['logo'],
                route('portfolio.banking.link.callback'),
            );

            return redirect()->away($link);
        } catch (Throwable) {
            return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('The bank connection could not be started. Please try again.'));
        }
    }
}
