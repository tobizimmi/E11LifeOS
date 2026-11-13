<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a loyalty-card photo (front or back) only to members who may see the card.
 */
class LoyaltyCardImageController extends Controller
{
    public function __invoke(Request $request, LoyaltyCard $card, string $side): StreamedResponse
    {
        $household = $request->user()->currentHousehold();

        abort_unless(
            $household && LoyaltyCard::query()->visibleTo($request->user(), $household->id)->whereKey($card->id)->exists(),
            404,
        );

        $path = $side === 'back' ? $card->back_image_path : $card->front_image_path;
        abort_unless(is_string($path) && $path !== '' && Storage::disk(LoyaltyCard::IMAGE_DISK)->exists($path), 404);

        return Storage::disk(LoyaltyCard::IMAGE_DISK)->response($path, null, [
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }
}
