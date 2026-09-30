<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityLog;
use App\Support\Security\VirusScanner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks every uploaded file (forms, Livewire uploads, the app API) with ClamAV before the app
 * sees it. Infected files are rejected and logged; when the scanner is unreachable, uploads are
 * rejected only if "block uploads without scan" is switched on.
 */
class ScanUploads
{
    public function __construct(private VirusScanner $scanner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $files = array_filter(Arr::flatten($request->allFiles()), fn ($file) => $file instanceof UploadedFile && $file->isValid());

        if ($files === [] || ! VirusScanner::isEnabled()) {
            return $next($request);
        }

        foreach ($files as $file) {
            $result = $this->scanner->scanFile($file->getRealPath());

            if ($result['status'] === VirusScanner::INFECTED) {
                SecurityLog::record($request->user(), 'virus_found', $request, $file->getClientOriginalName().': '.$result['signature']);

                throw ValidationException::withMessages([$this->field($request) => __('":name" contains a virus (:signature) and was not uploaded.', ['name' => $file->getClientOriginalName(), 'signature' => $result['signature']])]);
            }

            if ($result['status'] === VirusScanner::UNAVAILABLE && config('security.clamav.fail_closed')) {
                throw ValidationException::withMessages([$this->field($request) => __('Uploads are paused because the virus scanner is not reachable. Please try again later.')]);
            }
        }

        return $next($request);
    }

    /**
     * Livewire maps "files.0" to the upload property; forms get the error on their first file field.
     */
    private function field(Request $request): string
    {
        return $request->routeIs('livewire.upload-file') ? 'files.0' : (string) (array_key_first($request->allFiles()) ?? 'file');
    }
}
