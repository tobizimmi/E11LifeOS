<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\Business\XRechnung;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * An issued invoice or credit note as e-invoice (XRechnung, CII XML).
 */
class XRechnungController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->user_id === $request->user()->id && ! $invoice->isQuote() && ! $invoice->isDraft(), 404);

        return response(XRechnung::cii($invoice, BusinessProfile::where('user_id', $invoice->user_id)->firstOrFail()), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="xrechnung-'.Str::slug((string) $invoice->number).'.xml"',
        ]);
    }
}
