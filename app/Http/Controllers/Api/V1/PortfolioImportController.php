<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Support\AiException;
use App\Support\Portfolio\StatementAi;
use App\Support\Portfolio\TransactionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Broker import for the app: a CSV export, or a PDF/photo of a statement read by the AI, is parsed
 * into transactions the app shows for confirmation; the confirmed rows are then imported.
 */
class PortfolioImportController extends ApiController
{
    public function brokers(): JsonResponse
    {
        return $this->ok([
            'ai' => StatementAi::isConfigured(),
            'brokers' => collect(TransactionImporter::BROKERS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
        ]);
    }

    public function parse(Request $request, TransactionImporter $importer, StatementAi $ai): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,pdf,jpg,jpeg,png,webp,gif'],
            'broker' => ['nullable', 'string', 'max:30'],
        ]);

        $file = $request->file('file');
        $mime = (string) $file?->getMimeType();
        $contents = (string) file_get_contents((string) $file?->getRealPath());

        if (in_array($mime, StatementAi::MEDIA_TYPES, true)) {
            if (! StatementAi::isConfigured()) {
                throw ValidationException::withMessages(['file' => __('Reading PDFs and images needs the AI (ANTHROPIC_API_KEY). Please upload a CSV export.')]);
            }

            try {
                $rows = array_values(array_filter(array_map(fn (array $row): ?array => $importer->normalize($row), $ai->read($contents, $mime))));
            } catch (AiException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }
            $broker = 'ai';
            $errors = [];
        } else {
            // Exports of German banks are often Windows-1252 encoded.
            if (! mb_check_encoding($contents, 'UTF-8')) {
                $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
            }
            $parsed = $importer->parse($contents, (string) ($validated['broker'] ?? 'auto'));
            $broker = $parsed['broker'];
            $rows = array_slice($parsed['transactions'], 0, 5000);
            $errors = $parsed['errors'];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('No transactions found in this file.').($errors !== [] ? ' '.$errors[0] : '')]);
        }

        return $this->ok(['broker' => $broker, 'rows' => $rows, 'errors' => $errors]);
    }

    public function commit(Request $request, TransactionImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'portfolio_id' => ['required', 'integer'],
            'broker' => ['nullable', 'string', 'max:30'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);

        $portfolio = Portfolio::where('user_id', $this->user()->id)->findOrFail((int) $validated['portfolio_id']);
        $rows = array_values(array_filter(array_map(fn (array $row): ?array => $importer->normalize($row), $validated['rows'])));
        $result = $importer->import($portfolio, $rows, ($validated['broker'] ?? '') !== '' ? (string) $validated['broker'] : 'import');

        return $this->ok($result);
    }
}
