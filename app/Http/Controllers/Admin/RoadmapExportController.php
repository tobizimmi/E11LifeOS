<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Helpdesk\RoadmapExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The roadmap as a Markdown file to use with Claude.
 */
class RoadmapExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $milestone = $request->filled('milestone') ? (int) $request->query('milestone') : null;

        return response(RoadmapExport::markdown($milestone, $request->boolean('done')), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="roadmap-'.now()->format('Y-m-d').'.md"',
        ]);
    }
}
