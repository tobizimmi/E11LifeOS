<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the small business, part two: product catalogue, billing tracked times and
 * recurring invoices.
 */
class BusinessExtrasController extends ApiController
{
    public function products(): JsonResponse
    {
        $this->profile();

        return $this->ok($this->productList());
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $this->profile();
        Product::create(['user_id' => $this->user()->id, 'household_id' => $this->household()->id] + $this->productFields($request));

        return $this->ok($this->productList(), 201);
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        $this->profile();
        $this->product($product)->update($this->productFields($request));

        return $this->ok($this->productList());
    }

    public function destroyProduct(int $product): JsonResponse
    {
        $this->profile();
        $this->product($product)->delete();

        return $this->ok($this->productList());
    }

    /**
     * Manual time entry ("1:30", "1,5" or "90m" as duration).
     */
    public function storeTime(Request $request): JsonResponse
    {
        $this->profile();
        $data = $this->timeFields($request);
        TimeEntry::create(['user_id' => $this->user()->id, 'household_id' => $this->household()->id] + $data);

        return $this->ok(null, 201);
    }

    public function updateTime(Request $request, int $entry): JsonResponse
    {
        $this->profile();
        $this->openEntry($entry)->update($this->timeFields($request));

        return $this->ok();
    }

    /**
     * Turns the chosen unbilled entries of one customer into an invoice draft (one line per entry).
     */
    public function billTime(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:200'], 'entries.*' => ['integer']]);
        $entries = TimeEntry::where('user_id', $this->user()->id)->whereNull('invoice_id')->whereNotNull('ended_at')->whereIn('id', array_map('intval', $data['entries']))->orderBy('started_at')->get();
        abort_if($entries->isEmpty(), 422, __('Select the entries you want to bill.'));
        abort_if($entries->pluck('customer_id')->unique()->count() > 1, 422, __('Please select entries of one customer only.'));

        $first = $entries->firstOrFail();
        $last = $entries->last();
        $customer = $first->customer_id !== null ? Customer::find($first->customer_id) : null;
        $invoice = Invoice::create([
            'user_id' => $this->user()->id,
            'household_id' => $this->household()->id,
            'type' => Invoice::INVOICE,
            'customer_id' => $customer?->id,
            'status' => Invoice::DRAFT,
            'recipient' => $customer?->addressBlock() ?? '',
            'service_period' => Carbon::parse((string) $first->started_at)->format('d.m.Y').($entries->count() > 1 ? ' – '.Carbon::parse((string) $last->started_at)->format('d.m.Y') : ''),
            'intro' => __('Thank you for your order. I hereby invoice the following services:'),
            'closing' => $profile->invoice_footer,
            'small_business' => $profile->small_business,
        ]);

        foreach ($entries->values() as $position => $entry) {
            $invoice->items()->create([
                'position' => $position + 1,
                'description' => Carbon::parse((string) $entry->started_at)->format('d.m.Y').' – '.$entry->description,
                'quantity' => round($entry->duration() / 60, 2),
                'unit' => __('Hrs.'),
                'unit_price' => (float) ($entry->hourly_rate ?? $profile->hourly_rate ?? 0),
                'vat_rate' => $profile->small_business ? 0 : (float) $profile->default_vat_rate,
            ]);
            $entry->update(['invoice_id' => $invoice->id]);
        }

        $invoice->recalculate();

        return $this->ok(['invoice_id' => $invoice->id], 201);
    }

    public function recurring(): JsonResponse
    {
        $this->profile();

        return $this->ok($this->recurringList());
    }

    /**
     * Makes a recurring template out of an existing invoice.
     */
    public function storeRecurring(Request $request, int $invoice): JsonResponse
    {
        $this->profile();
        $data = $request->validate(['frequency' => ['required', Rule::in(array_keys(RecurringInvoice::FREQUENCIES))]]);
        $source = Invoice::where('user_id', $this->user()->id)->where('type', Invoice::INVOICE)->find($invoice) ?? abort(404);
        RecurringInvoice::fromInvoice($source, $data['frequency']);

        return $this->ok($this->recurringList(), 201);
    }

    public function updateRecurring(Request $request, int $template): JsonResponse
    {
        $this->profile();
        $model = $this->template($template);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'frequency' => ['sometimes', Rule::in(array_keys(RecurringInvoice::FREQUENCIES))],
            'next_run_on' => ['sometimes', 'date'],
            'ends_on' => ['nullable', 'date'],
            'auto_issue' => ['sometimes', 'boolean'],
            'auto_send' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $next = Carbon::parse((string) ($data['next_run_on'] ?? $model->next_run_on));
        abort_if(! empty($data['ends_on']) && Carbon::parse((string) $data['ends_on'])->lt($next), 422, __('Ends on'));

        if (array_key_exists('ends_on', $data)) {
            $data['ends_on'] = $data['ends_on'] ?: null;
        }

        $model->fill($data);
        $model->auto_send = $model->auto_send && $model->auto_issue;
        $model->save();

        return $this->ok($this->recurringList());
    }

    /**
     * Creates the next invoice right away.
     */
    public function runRecurring(int $template): JsonResponse
    {
        $profile = $this->profile();
        $invoice = $this->template($template)->run($profile);

        return $this->ok(['invoice_id' => $invoice->id], 201);
    }

    public function destroyRecurring(int $template): JsonResponse
    {
        $this->profile();
        $this->template($template)->delete();

        return $this->ok($this->recurringList());
    }

    /**
     * @return array<string, mixed>
     */
    private function productFields(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:24'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:99'],
        ]);

        return ['name' => trim($data['name']), 'description' => ($data['description'] ?? '') ?: null, 'unit' => ($data['unit'] ?? '') ?: null, 'unit_price' => round((float) $data['unit_price'], 2), 'vat_rate' => (float) $data['vat_rate']];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeFields(Request $request): array
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
            'duration' => ['required', 'string', 'max:10'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);
        $minutes = $this->parseDuration($data['duration']);
        abort_if($minutes === null || $minutes < 1 || $minutes > 24 * 60, 422, __('Enter the duration like 1:30 or 1,5.'));
        $customerId = isset($data['customer_id']) ? (Customer::where('user_id', $this->user()->id)->find($data['customer_id']) ?? abort(404))->id : null;
        $start = Carbon::parse($data['date'])->setTime(9, 0);

        return ['customer_id' => $customerId, 'description' => trim($data['description']), 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes((int) $minutes), 'minutes' => $minutes, 'hourly_rate' => isset($data['hourly_rate']) ? (float) $data['hourly_rate'] : null];
    }

    private function parseDuration(string $value): ?int
    {
        $value = trim(str_replace(',', '.', $value));

        return match (true) {
            (bool) preg_match('/^(\d{1,3}):([0-5]?\d)$/', $value, $match) => (int) $match[1] * 60 + (int) $match[2],
            (bool) preg_match('/^(\d{1,4})\s*m(in)?$/i', $value, $match) => (int) $match[1],
            is_numeric($value) => (int) round((float) $value * 60),
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productList(): array
    {
        $rows = [];

        foreach (Product::where('user_id', $this->user()->id)->orderBy('name')->get() as $product) {
            $rows[] = ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'unit' => $product->unit, 'unit_price' => $product->unit_price, 'vat_rate' => $product->vat_rate];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recurringList(): array
    {
        $rows = [];

        foreach (RecurringInvoice::where('user_id', $this->user()->id)->orderBy('next_run_on')->get() as $template) {
            $rows[] = [
                'id' => $template->id,
                'name' => $template->name,
                'frequency' => $template->frequency,
                'next_run_on' => Carbon::parse((string) $template->next_run_on)->toDateString(),
                'ends_on' => $template->ends_on !== null ? Carbon::parse((string) $template->ends_on)->toDateString() : null,
                'auto_issue' => $template->auto_issue,
                'auto_send' => $template->auto_send,
                'active' => $template->active,
                'amount' => $template->amount(),
            ];
        }

        return $rows;
    }

    private function product(int $id): Product
    {
        return Product::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    private function template(int $id): RecurringInvoice
    {
        return RecurringInvoice::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    private function openEntry(int $id): TimeEntry
    {
        return TimeEntry::where('user_id', $this->user()->id)->whereNull('invoice_id')->find($id) ?? abort(404);
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
