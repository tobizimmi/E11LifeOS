<?php

namespace App\Http\Controllers;

use App\Models\ExpenseGroup;
use App\Support\Life\ExpenseSplitter;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Export of an expense group (CSV for spreadsheets, PDF summary) and the read-only page behind
 * the share link.
 */
class ExpenseGroupController extends Controller
{
    public function export(Request $request, ExpenseGroup $group, string $format): Response
    {
        $household = $request->user()->currentHousehold();
        abort_unless($household && ExpenseGroup::query()->visibleTo($request->user(), $household->id)->whereKey($group->id)->exists(), 404);
        $filename = Str::slug($group->title) ?: 'kosten';

        if ($format === 'csv') {
            return response(ExpenseSplitter::csv($group), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
            ]);
        }

        $pdf = PdfTheme::render(view('splits.pdf', $this->summary($group))->render());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function shared(string $token): View
    {
        $group = ExpenseGroup::query()->whereShareToken('share_token', $token, 'share_expires_at')->first() ?? abort(404);

        return view('splits.public', $this->summary($group));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ExpenseGroup $group): array
    {
        $group->load(['members', 'expenses.shares', 'expenses.payer']);
        $balances = ExpenseSplitter::balances($group);

        return [
            'group' => $group,
            'balances' => $balances,
            'settlements' => ExpenseSplitter::settlements($balances),
            'stats' => ExpenseSplitter::statistics($group),
            'categories' => ExpenseSplitter::categories(),
            'names' => $group->members->pluck('name', 'id'),
        ];
    }
}
