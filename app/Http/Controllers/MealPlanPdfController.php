<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The meal plan of one week as a printable PDF, in the household's PDF design.
 */
class MealPlanPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $household = $user->currentHousehold();
        abort_if($household === null, 404);

        try {
            $start = ($request->filled('week') ? Carbon::createFromFormat('!Y-m-d', (string) $request->query('week')) : today())->startOfWeek(Carbon::MONDAY);
        } catch (\Throwable) {
            $start = today()->startOfWeek(Carbon::MONDAY);
        }

        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        $plans = MealPlan::query()->visibleTo($user, $household->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('recipe:id,title')
            ->orderBy('date')
            ->get()
            ->groupBy(fn (MealPlan $plan) => Carbon::parse($plan->date)->toDateString());

        $days = collect(range(0, 6))->map(function (int $offset) use ($start, $plans) {
            $date = $start->copy()->addDays($offset);
            $entries = collect($plans->get($date->toDateString(), collect()))
                ->sortBy(fn (MealPlan $plan) => array_search($plan->meal_type, MealPlan::MEAL_TYPES, true))
                ->values();

            return ['date' => $date, 'entries' => $entries];
        });

        $theme = PdfTheme::for($household->id, 'general');

        $html = view('recipes.meal-plan-pdf', [
            'title' => __('Meal plan'),
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'theme' => $theme,
        ])->render();

        $pdf = PdfTheme::render($html, $household->id, 'A4');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="wochenplan-'.$start->format('Y-m-d').'.pdf"',
        ]);
    }
}
