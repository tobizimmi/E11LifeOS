<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Household;
use App\Models\Invoice;
use App\Support\AiException;
use App\Support\Business\BookkeepingCategories;
use App\Support\Business\EuerReport;
use App\Support\Business\ReceiptAi;
use App\Support\Business\VatReturn;
use App\Support\PdfTheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile API for the bookkeeping of the small business: receipts (income and expenses without an
 * invoice from the app), the yearly profit calculation (EÜR) and the VAT advance returns. All
 * figures come from the same classes as in the web app.
 */
class BusinessBooksController extends ApiController
{
    public function bookings(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $year = $this->year($request, (int) now()->year);
        $kind = (string) $request->query('kind', '');
        $term = mb_strtolower(trim((string) $request->query('q', '')));
        $entries = $this->entries()
            ->whereYear('booked_on', $year)
            ->when(in_array($kind, [BookkeepingEntry::EXPENSE, BookkeepingEntry::INCOME], true), fn ($query) => $query->where('kind', $kind))
            ->when($kind === 'missing', fn ($query) => $query->where('kind', BookkeepingEntry::EXPENSE)->whereNull('receipt_path'))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(description) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(counterparty) LIKE ?', ['%'.$term.'%'])))
            ->orderByDesc('booked_on')->orderByDesc('id')->limit(500)->get();

        $categories = [];

        foreach (BookkeepingCategories::all() as $key => $category) {
            $categories[] = ['key' => $key, 'label' => $category['label'], 'icon' => $category['icon'], 'kind' => $category['kind'], 'hint' => $category['hint'] ?? null];
        }

        return $this->ok([
            'year' => $year,
            'years' => $this->years($year),
            'small_business' => (bool) $profile->small_business,
            'entries' => $entries->map(fn (BookkeepingEntry $entry) => $this->entryData($entry))->values()->all(),
            'expense_sum' => round((float) $entries->where('kind', BookkeepingEntry::EXPENSE)->sum('amount'), 2),
            'income_sum' => round((float) $entries->where('kind', BookkeepingEntry::INCOME)->sum('amount'), 2),
            'categories' => $categories,
            'ai' => ReceiptAi::isConfigured(),
        ]);
    }

    /**
     * New receipt; with multipart the receipt photo or PDF can be sent as "receipt".
     */
    public function store(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $entry = new BookkeepingEntry(['user_id' => $this->user()->id, 'household_id' => $this->household()->id]);
        $this->fill($request, $entry, $profile);

        return $this->ok($this->entryData($entry->refresh()), 201);
    }

    /**
     * Changes a receipt (POST so that a new receipt file can be sent as multipart); "remove_receipt" drops the file.
     */
    public function update(Request $request, int $entry): JsonResponse
    {
        $profile = $this->profile();
        $model = $this->entry($entry);
        $this->fill($request, $model, $profile);

        return $this->ok($this->entryData($model->refresh()));
    }

    public function destroy(int $entry): JsonResponse
    {
        $this->profile();
        $model = $this->entry($entry);

        if ($model->receipt_path) {
            Storage::disk(BookkeepingEntry::RECEIPT_DISK)->delete($model->receipt_path);
        }

        $model->delete();

        return $this->ok();
    }

    public function receipt(int $entry): StreamedResponse
    {
        $this->profile();
        $model = $this->entry($entry);
        abort_unless($model->receipt_path && Storage::disk(BookkeepingEntry::RECEIPT_DISK)->exists($model->receipt_path), 404);

        return Storage::disk(BookkeepingEntry::RECEIPT_DISK)->response($model->receipt_path, $model->receipt_name, [
            'Content-Type' => $model->receipt_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * Reads date, partner, amount, VAT and category from a photo or PDF of a receipt (AI).
     */
    public function readReceipt(Request $request, ReceiptAi $ai): JsonResponse
    {
        $this->profile();
        $data = $request->validate(['receipt' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'], 'kind' => ['sometimes', Rule::in([BookkeepingEntry::EXPENSE, BookkeepingEntry::INCOME])]]);
        $file = $request->file('receipt');
        abort_if($file === null || ! in_array($file->getMimeType(), ReceiptAi::MEDIA_TYPES, true), 422, __('Add a photo or PDF of the receipt first.'));

        try {
            $result = $ai->read((string) file_get_contents($file->getRealPath()), (string) $file->getMimeType(), $data['kind'] ?? BookkeepingEntry::EXPENSE);
        } catch (AiException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->ok($result);
    }

    /**
     * Profit calculation (EÜR) of a year, with the VAT advance returns for businesses that charge VAT.
     */
    public function report(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $year = $this->year($request, (int) (now()->month <= 5 ? now()->year - 1 : now()->year));
        $interval = $request->query('interval') === 'month' ? 'month' : 'quarter';
        $report = EuerReport::build($this->user(), $this->household()->id, $year, $profile);
        $definitions = BookkeepingCategories::euerLines();
        $lines = [];

        foreach ($definitions as $key => $definition) {
            $lines[] = ['key' => $key, 'label' => $definition['label'], 'section' => $definition['section'], 'amount' => $report['lines'][$key]];
        }

        $periods = [];

        if (! $profile->small_business) {
            foreach (VatReturn::periods($this->user(), $this->household()->id, $year, $interval) as $period) {
                $periods[] = [
                    'label' => $period['label'],
                    'from' => $period['from']->toDateString(),
                    'to' => $period['to']->toDateString(),
                    'kz81' => $period['kz81'],
                    'kz81_vat' => $period['kz81_vat'],
                    'kz86' => $period['kz86'],
                    'kz86_vat' => $period['kz86_vat'],
                    'other_net' => $period['other_net'],
                    'other_vat' => $period['other_vat'],
                    'vat_total' => round($period['kz81_vat'] + $period['kz86_vat'] + $period['other_vat'], 2),
                    'kz66' => $period['kz66'],
                    'kz83' => $period['kz83'],
                ];
            }
        }

        return $this->ok([
            'year' => $year,
            'years' => $this->years($year),
            'small_business' => (bool) $profile->small_business,
            'company' => $profile->company_name,
            'income_total' => $report['incomeTotal'],
            'expense_total' => $report['expenseTotal'],
            'profit' => $report['profit'],
            'revenue' => $report['revenue'],
            'limit_status' => $report['limitStatus'],
            'vat' => $report['vat'],
            'monthly' => $report['monthly'],
            'lines' => $lines,
            'non_deductible' => $report['nonDeductible'],
            'missing_receipts' => $report['missingReceipts'],
            'open_invoice_count' => $report['openInvoiceCount'],
            'open_invoice_total' => $report['openInvoiceTotal'],
            'interval' => $interval,
            'vat_periods' => $periods,
        ]);
    }

    /**
     * The profit calculation as PDF (for the tax return) or all bookings of the year as CSV.
     */
    public function download(Request $request): Response
    {
        $profile = $this->profile();
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'format' => ['required', 'in:pdf,csv']]);
        $report = EuerReport::build($this->user(), $this->household()->id, (int) $data['year'], $profile);

        if ($data['format'] === 'csv') {
            return response(EuerReport::csv($report), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="buchungen-'.$report['year'].'.csv"']);
        }

        $html = view('business.report-pdf', ['report' => $report, 'profile' => $profile, 'lineDefinitions' => BookkeepingCategories::euerLines()])->render();

        return response(PdfTheme::render($html), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="euer-'.$report['year'].'.pdf"']);
    }

    private function fill(Request $request, BookkeepingEntry $entry, BusinessProfile $profile): void
    {
        $kind = (string) $request->input('kind', $entry->kind ?: BookkeepingEntry::EXPENSE);
        $data = $request->validate([
            'kind' => ['required', Rule::in([BookkeepingEntry::EXPENSE, BookkeepingEntry::INCOME])],
            'booked_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', fn ($attribute, $value, $fail) => BookkeepingCategories::exists((string) $value, $kind) ? null : $fail(__('Please choose a category.'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'lt:amount'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
            'remove_receipt' => ['sometimes', 'boolean'],
        ]);
        $attributes = [
            'kind' => $data['kind'],
            'booked_on' => $data['booked_on'],
            'description' => $data['description'],
            'counterparty' => ($data['counterparty'] ?? '') ?: null,
            'category' => $data['category'],
            'amount' => (float) $data['amount'],
            'vat_amount' => $profile->small_business ? 0.0 : (float) ($data['vat_amount'] ?? 0),
        ];
        $file = $request->file('receipt');

        if (($request->boolean('remove_receipt') || $file !== null) && $entry->receipt_path) {
            Storage::disk(BookkeepingEntry::RECEIPT_DISK)->delete($entry->receipt_path);
            $attributes += ['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null];
        }

        if ($file !== null) {
            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $attributes['receipt_path'] = $file->storeAs('business-receipts/'.$this->user()->id, Str::uuid().'.'.$extension, BookkeepingEntry::RECEIPT_DISK);
            $attributes['receipt_name'] = mb_substr($file->getClientOriginalName(), 0, 255);
            $attributes['receipt_mime'] = $file->getMimeType();
        }

        $entry->fill($attributes)->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(BookkeepingEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'booked_on' => Carbon::parse((string) $entry->booked_on)->toDateString(),
            'description' => $entry->description,
            'counterparty' => $entry->counterparty,
            'category' => $entry->category,
            'category_label' => BookkeepingCategories::label($entry->category),
            'amount' => $entry->amount,
            'vat_amount' => $entry->vat_amount,
            'has_receipt' => $entry->receipt_path !== null,
            'receipt_name' => $entry->receipt_name,
        ];
    }

    /**
     * @return list<int>
     */
    private function years(int $selected): array
    {
        $years = [(int) now()->year, (int) now()->year - 1, $selected];

        foreach (Invoice::where('user_id', $this->user()->id)->whereNotNull('paid_on')->pluck('paid_on') as $date) {
            $years[] = Carbon::parse((string) $date)->year;
        }

        foreach ($this->entries()->pluck('booked_on') as $date) {
            $years[] = Carbon::parse((string) $date)->year;
        }

        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    private function year(Request $request, int $default): int
    {
        $year = (int) $request->query('year', (string) $default);

        return $year >= 2000 && $year <= 2100 ? $year : $default;
    }

    /**
     * @return Builder<BookkeepingEntry>
     */
    private function entries(): Builder
    {
        return BookkeepingEntry::where('user_id', $this->user()->id)->where('household_id', $this->household()->id);
    }

    private function entry(int $id): BookkeepingEntry
    {
        return $this->entries()->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function profile(): BusinessProfile
    {
        return BusinessProfile::where('user_id', $this->user()->id)->first() ?? abort(404, __('Please set up your business in the web app first.'));
    }
}
