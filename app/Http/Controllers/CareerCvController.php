<?php

namespace App\Http\Controllers;

use App\Models\CareerEntry;
use App\Support\Career\CareerPlanner;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The member's CV as PDF: profile, work experience, education, further training and skills.
 */
class CareerCvController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $householdId = (int) $user->currentHousehold()?->id;
        $timeline = CareerPlanner::timeline(CareerEntry::where('user_id', $user->id)->where('household_id', $householdId)->get());

        $html = view('career.cv', [
            'user' => $user,
            'profile' => CareerPlanner::profile($user),
            'skills' => CareerPlanner::skills($user),
            'sections' => [
                __('Work experience') => $timeline->where('type', 'job'),
                __('Education') => $timeline->where('type', 'education'),
                __('Further training') => $timeline->where('type', 'training'),
            ],
        ])->render();

        return response(PdfTheme::render($html, $householdId, footer: false), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.(Str::slug(__('CV').' '.$user->name, '-', 'de') ?: 'lebenslauf').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
