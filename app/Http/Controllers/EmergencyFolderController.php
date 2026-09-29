<?php

namespace App\Http\Controllers;

use App\Models\EmergencyEntry;
use App\Models\TrustedPerson;
use App\Models\User;
use App\Support\Life\EmergencyFolder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The emergency folder as PDF: for the owner and, once their access is open, for a trusted person.
 */
class EmergencyFolderController extends Controller
{
    public function pdf(Request $request): Response
    {
        return $this->render($request->user());
    }

    public function sharedPdf(string $token): Response
    {
        $person = TrustedPerson::findByToken($token);
        abort_unless($person?->hasAccess() && $person->user, 404);

        return $this->render($person->user);
    }

    private function render(User $owner): Response
    {
        $html = view('emergency.pdf', [
            'owner' => $owner,
            'sections' => EmergencyFolder::sections(),
            'entries' => EmergencyEntry::where('user_id', $owner->id)->orderBy('position')->orderBy('id')->get(),
            'overview' => EmergencyFolder::overview($owner),
        ])->render();

        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response((string) $dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.__('Emergency folder').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
