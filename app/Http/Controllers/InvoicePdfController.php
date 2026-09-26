<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\Business\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The document as PDF (DIN 5008 layout) for its owner; drafts are marked as such.
 * With ?reminder=1..3 the payment reminder or dunning letter of an open invoice.
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        $profile = BusinessProfile::where('user_id', $invoice->user_id)->firstOrFail();
        $level = $request->filled('reminder') ? max(1, min(3, $request->integer('reminder'))) : null;

        if ($level !== null) {
            abort_unless($invoice->type === Invoice::INVOICE && $invoice->status === Invoice::OPEN, 404);
        }

        return response($level ? DocumentPdf::reminder($invoice, $profile, $level) : DocumentPdf::document($invoice, $profile), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.DocumentPdf::filename($invoice, $level).'"',
        ]);
    }
}
