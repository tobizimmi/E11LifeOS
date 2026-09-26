<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\Business\DocumentPdf;
use App\Support\WebPush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Customer portal: a customer opens an invoice, quote or credit note with the link from the
 * e-mail (random token, no login), downloads the PDF and can accept a quote.
 */
class PublicDocumentController extends Controller
{
    public function show(string $token): View
    {
        $invoice = $this->find($token);

        return view('business.public', [
            'invoice' => $invoice->load('items'),
            'profile' => BusinessProfile::where('user_id', $invoice->user_id)->firstOrFail(),
        ]);
    }

    public function pdf(string $token): Response
    {
        $invoice = $this->find($token);

        return response(DocumentPdf::document($invoice, BusinessProfile::where('user_id', $invoice->user_id)->firstOrFail()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocumentPdf::filename($invoice).'"',
        ]);
    }

    public function accept(string $token, WebPush $push): RedirectResponse
    {
        $quote = $this->find($token);
        abort_unless($quote->isQuote() && $quote->status === Invoice::OPEN && ! $quote->isExpired(), 422);

        $quote->update(['status' => Invoice::ACCEPTED]);
        $push->send($quote->user, '✅ '.__('Quote :number accepted', ['number' => $quote->number]), strtok($quote->recipient, "\n") ?: '', route('business.index', ['type' => Invoice::QUOTE, 'document' => $quote->id]));

        return redirect()->route('documents.public', $token)->with('status', __('Thank you! The quote has been accepted.'));
    }

    private function find(string $token): Invoice
    {
        return Invoice::where('public_token', $token)->whereNotNull('number')->where('status', '!=', Invoice::DRAFT)->firstOrFail();
    }
}
