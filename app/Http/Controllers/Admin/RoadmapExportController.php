<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Helpdesk\RoadmapExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roadmap as a Markdown file to use with Claude. When milestones have images attached, it is a
 * ZIP that bundles the Markdown with those images instead.
 */
class RoadmapExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $milestone = $request->filled('milestone') ? (int) $request->query('milestone') : null;
        $withDone = $request->boolean('done');
        $date = now()->format('Y-m-d');

        if (RoadmapExport::hasImages($milestone)) {
            return response()->download(
                RoadmapExport::archive($milestone, $withDone),
                'roadmap-'.$date.'.zip',
                ['Content-Type' => 'application/zip'],
            )->deleteFileAfterSend();
        }

        return response(RoadmapExport::markdown($milestone, $withDone), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="roadmap-'.$date.'.md"',
        ]);
    }
}
