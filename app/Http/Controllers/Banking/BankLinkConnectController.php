<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankLink;
use App\Support\Banking\BankLinkSync;
use App\Support\Banking\EnableBankingClient;
use App\Support\Banking\GoCardlessClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Starts a PSD2 consent for the chosen bank and sends the user to the bank's hosted login. A normal
 * request (not Livewire) so the redirect to the external bank page works cleanly. The provider
 * (gocardless or enablebanking) is chosen per request.
 */
class BankLinkConnectController extends Controller
{
    public function __invoke(Request $request, GoCardlessClient $gocardless, EnableBankingClient $enableBanking, BankLinkSync $sync): RedirectResponse
    {
        $provider = $request->query('provider') === BankLink::PROVIDER_ENABLEBANKING
            ? BankLink::PROVIDER_ENABLEBANKING
            : BankLink::PROVIDER_GOCARDLESS;
        $institutionId = (string) $request->query('institution');
        $callback = route('portfolio.banking.link.callback');

        // Outside the try: a 404 for an unconfigured provider must surface, not be swallowed as a redirect.
        abort_unless(
            $provider === BankLink::PROVIDER_ENABLEBANKING ? $enableBanking->configured() : $gocardless->configured(),
            404
        );

        try {
            if ($provider === BankLink::PROVIDER_ENABLEBANKING) {
                $bank = collect($enableBanking->institutions('DE'))->firstWhere('id', $institutionId);

                if (! $bank) {
                    return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('Please choose a bank.'));
                }

                $link = $sync->connectEnableBanking($request->user(), $bank['id'], $bank['country'], $bank['logo'] ?? null, $callback);

                return redirect()->away($link);
            }

            $institution = $institutionId !== '' ? $gocardless->institution($institutionId) : null;

            if (! $institution) {
                return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('Please choose a bank.'));
            }

            $link = $sync->connect($request->user(), $institution['id'], $institution['name'], $institution['logo'], $callback);

            return redirect()->away($link);
        } catch (Throwable $exception) {
            // Log it so the real reason (e.g. redirect URL not registered, invalid key) shows under Admin › System.
            report($exception);

            return redirect()->route('portfolio.banking')->with('bankLinkStatus', __('The bank connection could not be started. Please try again.'));
        }
    }
}
