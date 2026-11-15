<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\TimeEntry;
use App\Support\Business\DocumentPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the small business: invoices and quotes, customers and time tracking. The business
 * details, bookkeeping, VAT and the reports stay in the web app.
 */
class BusinessController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $type = $request->query('type', Invoice::INVOICE);
        abort_unless(in_array($type, Invoice::TYPES, true), 422);

        $invoices = [];

        foreach (Invoice::where('user_id', $this->user()->id)->where('type', $type)->latest('id')->limit(200)->get() as $invoice) {
            $invoices[] = $this->invoiceCard($invoice);
        }

        $customers = [];

        foreach ($this->customerQuery()->orderBy('name')->get() as $customer) {
            $customers[] = $this->customerData($customer);
        }

        $open = 0.0;

        foreach (Invoice::where('user_id', $this->user()->id)->where('type', Invoice::INVOICE)->where('status', Invoice::OPEN)->get() as $invoice) {
            $open += $invoice->outstanding();
        }

        return $this->ok([
            'company' => $profile->company_name,
            'complete' => $profile->isCompleteForInvoices(),
            'small_business' => (bool) $profile->small_business,
            'default_vat_rate' => (float) $profile->default_vat_rate,
            'hourly_rate' => $profile->hourly_rate !== null ? (float) $profile->hourly_rate : null,
            'open_total' => round($open, 2),
            'invoices' => $invoices,
            'customers' => $customers,
        ]);
    }

    public function showInvoice(int $invoice): JsonResponse
    {
        return $this->ok($this->invoiceDetail($this->invoice($invoice)));
    }

    /**
     * Creates a draft (or issues it right away with "issue").
     */
    public function storeInvoice(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(Invoice::TYPES)],
            'customer_id' => ['nullable', 'integer'],
            'recipient' => ['required', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'service_period' => ['nullable', 'string', 'max:255'],
            'due_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'closing' => ['nullable', 'string', 'max:2000'],
            'issue' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'min:-99999', 'max:99999'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.unit_price' => ['required', 'numeric', 'min:-9999999', 'max:9999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ]);
        $type = $data['type'] ?? Invoice::INVOICE;
        $issue = ! empty($data['issue']);
        abort_if($issue && ! $profile->isCompleteForInvoices(), 422, __('Please complete your business details (address and tax number) in the settings first.'));
        abort_if($issue && $type === Invoice::INVOICE && empty($data['service_period']), 422, __('Please enter the service date / period.'));
        $customerId = isset($data['customer_id']) ? ($this->customerQuery()->find($data['customer_id']) ?? abort(404))->id : null;

        $invoice = new Invoice([
            'user_id' => $this->user()->id,
            'household_id' => $this->household()->id,
            'type' => $type,
            'status' => Invoice::DRAFT,
            'customer_id' => $customerId,
            'recipient' => trim($data['recipient']),
            'subject' => trim((string) ($data['subject'] ?? '')) ?: null,
            'service_period' => trim((string) ($data['service_period'] ?? '')) ?: null,
            'due_on' => $type === Invoice::INVOICE ? ($data['due_on'] ?? null) : null,
            'valid_until' => $type === Invoice::QUOTE ? ($data['valid_until'] ?? null) : null,
            'intro' => trim((string) ($data['intro'] ?? '')) ?: null,
            'closing' => trim((string) ($data['closing'] ?? '')) ?: null,
            'small_business' => $profile->small_business,
        ]);
        $invoice->save();

        foreach (array_values($data['items']) as $position => $item) {
            $invoice->items()->create([
                'position' => $position + 1,
                'description' => trim($item['description']),
                'quantity' => (float) $item['quantity'],
                'unit' => trim((string) ($item['unit'] ?? '')) ?: null,
                'unit_price' => round((float) $item['unit_price'], 2),
                'discount_percent' => (float) ($item['discount'] ?? 0),
                'vat_rate' => $profile->small_business ? 0 : (float) ($item['vat_rate'] ?? $profile->default_vat_rate),
            ]);
        }

        $invoice->recalculate();

        if ($issue) {
            if ($invoice->due_on && Carbon::parse((string) $invoice->due_on)->lt(today())) {
                $invoice->due_on = null;
            }

            $invoice->issue($profile);
        }

        return $this->ok($this->invoiceDetail($invoice->refresh()), 201);
    }

    public function issueInvoice(int $invoice): JsonResponse
    {
        $profile = $this->profile();
        $item = $this->invoice($invoice);
        abort_unless($item->isDraft(), 422);
        abort_unless($profile->isCompleteForInvoices(), 422, __('Please complete your business details (address and tax number) in the settings first.'));
        abort_if($item->type === Invoice::INVOICE && blank($item->service_period), 422, __('Please enter the service date / period.'));
        $item->issue($profile);

        return $this->ok($this->invoiceDetail($item->refresh()));
    }

    public function destroyInvoice(int $invoice): JsonResponse
    {
        $item = $this->invoice($invoice);
        abort_unless($item->isDraft(), 422, __('Only drafts can be deleted.'));
        $item->delete();

        return $this->ok();
    }

    /**
     * Records a payment; without an amount the rest of the invoice is paid today.
     */
    public function pay(Request $request, int $invoice): JsonResponse
    {
        $item = $this->invoice($invoice);
        abort_unless($item->isPayable(), 422);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'], 'paid_on' => ['nullable', 'date'], 'method' => ['nullable', 'string', 'max:40']]);
        $item->recordPayment(Carbon::parse($data['paid_on'] ?? today()->toDateString()), (float) ($data['amount'] ?? $item->outstanding()), $data['method'] ?? null);

        return $this->ok($this->invoiceDetail($item->refresh()));
    }

    public function pdf(int $invoice): Response
    {
        $item = $this->invoice($invoice);
        $profile = $this->profile();

        return response(DocumentPdf::document($item, $profile), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.DocumentPdf::filename($item, null).'"']);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $customer = Customer::create($this->customerFields($request, true) + ['user_id' => $this->user()->id, 'household_id' => $this->household()->id]);

        return $this->ok($this->customerData($customer), 201);
    }

    public function updateCustomer(Request $request, int $customer): JsonResponse
    {
        $item = $this->customer($customer);
        $item->update($this->customerFields($request, false));

        return $this->ok($this->customerData($item->refresh()));
    }

    public function destroyCustomer(int $customer): JsonResponse
    {
        $this->customer($customer)->delete();

        return $this->ok();
    }

    public function time(): JsonResponse
    {
        $this->profile();
        $entries = [];
        $running = null;

        foreach (TimeEntry::where('user_id', $this->user()->id)->latest('started_at')->limit(100)->get() as $entry) {
            $row = $this->timeData($entry);

            if ($entry->ended_at === null) {
                $running = $row;
            } else {
                $entries[] = $row;
            }
        }

        return $this->ok(['running' => $running, 'entries' => $entries]);
    }

    public function startTimer(Request $request): JsonResponse
    {
        $profile = $this->profile();
        $data = $request->validate(['description' => ['required', 'string', 'max:255'], 'customer_id' => ['nullable', 'integer']]);
        $this->stopRunning();
        $customerId = isset($data['customer_id']) ? ($this->customerQuery()->find($data['customer_id']) ?? abort(404))->id : null;
        TimeEntry::create(['user_id' => $this->user()->id, 'household_id' => $this->household()->id, 'customer_id' => $customerId, 'description' => trim($data['description']), 'started_at' => now(), 'hourly_rate' => $profile->hourly_rate]);

        return $this->time();
    }

    public function stopTimer(): JsonResponse
    {
        $this->stopRunning();

        return $this->time();
    }

    public function destroyTime(int $entry): JsonResponse
    {
        (TimeEntry::where('user_id', $this->user()->id)->whereNull('invoice_id')->find($entry) ?? abort(404))->delete();

        return $this->time();
    }

    private function stopRunning(): void
    {
        foreach (TimeEntry::where('user_id', $this->user()->id)->whereNull('ended_at')->get() as $entry) {
            $entry->update(['ended_at' => now(), 'minutes' => max(1, (int) Carbon::parse((string) $entry->started_at)->diffInMinutes(now()))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerFields(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        return $data;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function profile(): BusinessProfile
    {
        return BusinessProfile::where('user_id', $this->user()->id)->first() ?? abort(404, __('Please set up your business in the web app first.'));
    }

    /**
     * @return Builder<Customer>
     */
    private function customerQuery(): Builder
    {
        return Customer::where('user_id', $this->user()->id);
    }

    private function customer(int $id): Customer
    {
        return $this->customerQuery()->find($id) ?? abort(404);
    }

    private function invoice(int $id): Invoice
    {
        return Invoice::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    private function date(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse((string) $value)->toDateString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerData(Customer $customer): array
    {
        return ['id' => $customer->id, 'name' => $customer->name, 'contact_person' => $customer->contact_person, 'street' => $customer->street, 'postal_code' => $customer->postal_code, 'city' => $customer->city, 'email' => $customer->email, 'vat_id' => $customer->vat_id, 'notes' => $customer->notes];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeData(TimeEntry $entry): array
    {
        $customer = $entry->customer_id !== null ? Customer::find($entry->customer_id) : null;

        return [
            'id' => $entry->id,
            'description' => $entry->description,
            'customer' => $customer?->name,
            'started_at' => Carbon::parse((string) $entry->started_at)->toIso8601String(),
            'ended_at' => $entry->ended_at !== null ? Carbon::parse((string) $entry->ended_at)->toIso8601String() : null,
            'minutes' => $entry->minutes,
            'hourly_rate' => $entry->hourly_rate,
            'billed' => $entry->invoice_id !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceCard(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'type' => $invoice->type,
            'number' => $invoice->number,
            'status' => $invoice->displayStatus(),
            'recipient' => $invoice->recipient,
            'subject' => $invoice->subject,
            'issued_on' => $this->date($invoice->issued_on),
            'due_on' => $this->date($invoice->due_on),
            'gross_total' => (float) $invoice->gross_total,
            'outstanding' => $invoice->outstanding(),
            'draft' => $invoice->isDraft(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceDetail(Invoice $invoice): array
    {
        $items = [];

        foreach (InvoiceItem::where('invoice_id', $invoice->id)->orderBy('position')->get() as $item) {
            $items[] = ['id' => $item->id, 'description' => $item->description, 'quantity' => (float) $item->quantity, 'unit' => $item->unit, 'unit_price' => (float) $item->unit_price, 'discount_percent' => (float) $item->discount_percent, 'vat_rate' => (float) $item->vat_rate];
        }

        $payments = [];

        foreach (InvoicePayment::where('invoice_id', $invoice->id)->orderBy('paid_on')->get() as $payment) {
            $payments[] = ['id' => $payment->id, 'paid_on' => $this->date($payment->paid_on), 'amount' => (float) $payment->amount, 'method' => $payment->method];
        }

        return $this->invoiceCard($invoice) + [
            'service_period' => $invoice->service_period,
            'intro' => $invoice->intro,
            'closing' => $invoice->closing,
            'net_total' => (float) $invoice->net_total,
            'vat_total' => (float) $invoice->vat_total,
            'paid_total' => (float) $invoice->paid_total,
            'payable' => $invoice->isPayable(),
            'items' => $items,
            'payments' => $payments,
        ];
    }
}
