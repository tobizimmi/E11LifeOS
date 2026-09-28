<?php

namespace App\Http\Controllers;

use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the receipt of a property invoice to members who can see the property.
 */
class RealEstateReceiptController extends Controller
{
    public function __invoke(Request $request, RealEstateInvoice $invoice): StreamedResponse
    {
        $user = $request->user();
        $visible = RealEstate::query()->visibleTo($user, (int) $user->currentHousehold()?->id)->whereKey($invoice->real_estate_id)->exists();

        abort_unless($visible && $invoice->receipt_path && Storage::disk(RealEstateInvoice::RECEIPT_DISK)->exists($invoice->receipt_path), 404);

        $inline = str_starts_with((string) $invoice->receipt_mime, 'image/') || $invoice->receipt_mime === 'application/pdf';

        return Storage::disk(RealEstateInvoice::RECEIPT_DISK)->response($invoice->receipt_path, $invoice->receipt_name, [
            'Content-Type' => $invoice->receipt_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }
}
