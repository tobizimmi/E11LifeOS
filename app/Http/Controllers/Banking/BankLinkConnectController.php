<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankLink;
use App\Support\Banking\BankLinkSync;
use App\Support\Banking\EnableBankingClient;
use App\Support\Banking\GoCardlessClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                    return $this->back(__('Please choose a bank.'));
                }

                $link = $sync->connectEnableBanking($request->user(), $bank['id'], $bank['country'], $bank['logo'] ?? null, $callback);

                if ($link === '') {
                    return $this->back(__('The bank did not return a login link. Please check that the redirect URL :url is registered in the Enable Banking application.', ['url' => $callback]));
                }

                return redirect()->away($link);
            }

            $institution = $institutionId !== '' ? $gocardless->institution($institutionId) : null;

            if (! $institution) {
                return $this->back(__('Please choose a bank.'));
            }

            $link = $sync->connect($request->user(), $institution['id'], $institution['name'], $institution['logo'], $callback);

            return redirect()->away($link);
        } catch (Throwable $exception) {
            // Log it so the full reason also shows under Admin › System.
            report($exception);

            return $this->back(__('The bank connection could not be started: :reason', ['reason' => $this->reason($exception, $callback)]));
        }
    }

    /**
     * Redirects back to the banking page with an error banner (red, not the success green).
     */
    private function back(string $message): RedirectResponse
    {
        return redirect()->route('portfolio.banking')
            ->with('bankLinkStatus', $message)
            ->with('bankLinkStatusType', 'error');
    }

    /**
     * A concise, human-readable reason from the failure – the bank/API error body when there is one,
     * so the owner sees e.g. an unregistered redirect URL instead of a generic hint.
     */
    private function reason(Throwable $exception, string $callback): string
    {
        if ($exception instanceof RequestException) {
            $body = $exception->response->json();
            $detail = is_array($body) ? ($body['message'] ?? $body['error'] ?? $body['detail'] ?? null) : null;
            $reason = is_string($detail) && $detail !== '' ? $detail : Str::limit($exception->response->body(), 200);

            return $reason.' '.__('(Make sure the redirect URL :url is registered in the Enable Banking application.)', ['url' => $callback]);
        }

        return Str::limit($exception->getMessage(), 200);
    }
}
