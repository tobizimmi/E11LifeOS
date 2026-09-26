<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The invoice as PDF (DIN 5008 layout) for its owner; drafts are marked as such.
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);

        $html = view('business.invoice-pdf', [
            'invoice' => $invoice->load('items', 'customer', 'related'),
            'profile' => BusinessProfile::where('user_id', $invoice->user_id)->firstOrFail(),
        ])->render();

        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $kind = match ($invoice->type) {
            Invoice::QUOTE => 'angebot',
            Invoice::CREDIT_NOTE => 'gutschrift',
            default => 'rechnung',
        };
        $name = $invoice->number ? $kind.'-'.Str::slug($invoice->number) : $kind.'-entwurf-'.$invoice->id;

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'.pdf"',
        ]);
    }
}
