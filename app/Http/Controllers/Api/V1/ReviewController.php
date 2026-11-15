<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\Life\YearReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The year in review for the app: money, everyday life and the car at a glance.
 */
class ReviewController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $now = (int) now()->year;
        $year = (int) $request->integer('year', $now);
        $year = ($year < 2000 || $year > $now) ? $now : $year;

        return $this->ok([
            'review' => YearReview::for($this->user(), $year),
            'years' => range($now, max(2020, (int) $this->user()->created_at->year - 1)),
        ]);
    }
}
