<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use App\Models\RealEstateInvoice;
use App\Support\RealEstate\RealEstatePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mobile API for real estate: the properties with their key figures, the budget by trade and the
 * invoices. Financing, subsidies, rentals and the profitability check stay in the web app for now.
 */
class RealEstateController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $rows = [];

        foreach (RealEstate::query()->visibleTo($this->user(), $household->id)->orderBy('name')->get() as $estate) {
            $rows[] = $this->card($estate);
        }

        return $this->ok([
            'properties' => $rows,
            'usages' => RealEstate::usageLabels(),
            'states' => RealEstate::stateNames(),
            'trades' => RealEstateBudgetItem::tradeLabels(),
            'statuses' => RealEstateBudgetItem::statusLabels(),
        ]);
    }

    public function show(int $estate): JsonResponse
    {
        return $this->ok($this->detail($this->find($this->household(), $estate)));
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'usage' => ['required', Rule::in(RealEstate::USAGES)],
            'state' => ['required', Rule::in(array_keys(RealEstate::TRANSFER_TAX))],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'private' => ['sometimes', 'boolean'],
        ]);
        $estate = RealEstate::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => ! empty($data['private']) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'name' => trim($data['name']),
            'usage' => $data['usage'],
            'rental_share' => $data['usage'] === RealEstate::MIXED ? 50 : 0,
            'state' => $data['state'],
            'purchase_price' => isset($data['purchase_price']) ? (float) $data['purchase_price'] : null,
        ]);

        return $this->ok($this->detail($estate), 201);
    }

    public function update(Request $request, int $estate): JsonResponse
    {
        $item = $this->find($this->household(), $estate);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(RealEstate::STATUSES)],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'living_area' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'equity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'monthly_rent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'monthly_costs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $item->update($data);

        return $this->ok($this->detail($item->refresh()));
    }

    public function destroy(int $estate): JsonResponse
    {
        $this->find($this->household(), $estate)->delete();

        return $this->ok();
    }

    public function addItem(Request $request, int $estate): JsonResponse
    {
        $item = $this->find($this->household(), $estate);
        $data = $this->itemData($request, true);
        $item->budgetItems()->create($data + ['phase' => $data['phase'] ?? 'renovation', 'status' => 'planned', 'position' => (int) $item->budgetItems()->max('position') + 1]);

        return $this->ok($this->detail($item->refresh()), 201);
    }

    public function updateItem(Request $request, int $estate, int $item): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $this->budgetItem($property, $item)->update($this->itemData($request, false));

        return $this->ok($this->detail($property->refresh()));
    }

    public function destroyItem(int $estate, int $item): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $this->budgetItem($property, $item)->delete();

        return $this->ok($this->detail($property->refresh()));
    }

    public function addInvoice(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $data = $this->invoiceData($request, $property);
        RealEstateInvoice::create($data + ['real_estate_id' => $property->id, 'created_by' => $this->user()->id]);

        return $this->ok($this->detail($property->refresh()), 201);
    }

    public function updateInvoice(Request $request, int $estate, int $invoice): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $this->invoice($property, $invoice)->update($this->invoiceData($request, $property, false));

        return $this->ok($this->detail($property->refresh()));
    }

    /**
     * Marks an invoice paid today (or open again).
     */
    public function togglePaid(int $estate, int $invoice): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $row = $this->invoice($property, $invoice);
        $row->update(['paid_on' => $row->isPaid() ? null : today()]);

        return $this->ok($this->detail($property->refresh()));
    }

    public function destroyInvoice(int $estate, int $invoice): JsonResponse
    {
        $property = $this->find($this->household(), $estate);
        $this->invoice($property, $invoice)->delete();

        return $this->ok($this->detail($property->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'trade' => ['sometimes', Rule::in(RealEstateBudgetItem::TRADES)],
            'phase' => ['sometimes', Rule::in(RealEstateBudgetItem::PHASES)],
            'planned_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'status' => ['sometimes', Rule::in(RealEstateBudgetItem::STATUSES)],
            'contractor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceData(Request $request, RealEstate $property, bool $creating = true): array
    {
        $data = $request->validate([
            'vendor' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100000000'],
            'invoice_date' => ['sometimes', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'budget_item_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        if (! empty($data['budget_item_id'])) {
            $this->budgetItem($property, (int) $data['budget_item_id']);
        }

        if ($creating) {
            $data += ['invoice_date' => today()->toDateString()];
        }

        return $data;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(Household $household, int $id): RealEstate
    {
        return RealEstate::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    private function budgetItem(RealEstate $property, int $id): RealEstateBudgetItem
    {
        return RealEstateBudgetItem::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function invoice(RealEstate $property, int $id): RealEstateInvoice
    {
        return RealEstateInvoice::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function date(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse((string) $value)->toDateString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(RealEstate $estate): array
    {
        $summary = RealEstatePlan::summary($estate);

        return [
            'id' => $estate->id,
            'name' => $estate->name,
            'city' => $estate->city,
            'usage' => $estate->usage,
            'status' => $estate->status,
            'private' => $estate->visibility === Item::VISIBILITY_PRIVATE,
            'expected_total' => $summary['expected_total'],
            'invoiced_total' => $summary['invoiced_total'],
            'cashflow' => $summary['monthly']['cashflow'],
            'progress' => $summary['progress'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(RealEstate $estate): array
    {
        $summary = RealEstatePlan::summary($estate);
        $items = [];

        foreach ($summary['items'] as $row) {
            /** @var RealEstateBudgetItem $budget */
            $budget = $row['item'];
            $items[] = ['id' => $budget->id, 'title' => $budget->title, 'trade' => $budget->trade, 'phase' => $budget->phase, 'status' => $budget->status, 'contractor' => $budget->contractor, 'planned' => $row['planned'], 'invoiced' => $row['invoiced'], 'paid' => $row['paid'], 'over' => $row['over']];
        }

        $invoices = [];

        foreach (RealEstateInvoice::where('real_estate_id', $estate->id)->latest('id')->limit(200)->get() as $invoice) {
            $invoices[] = ['id' => $invoice->id, 'vendor' => $invoice->vendor, 'number' => $invoice->number, 'amount' => $invoice->amount, 'invoice_date' => $this->date($invoice->invoice_date), 'due_on' => $this->date($invoice->due_on), 'paid' => $invoice->isPaid(), 'budget_item_id' => $invoice->budget_item_id];
        }

        return $this->card($estate) + [
            'street' => $estate->street,
            'postal_code' => $estate->postal_code,
            'purchase_price' => $estate->purchase_price !== null ? (float) $estate->purchase_price : null,
            'living_area' => $estate->living_area !== null ? (float) $estate->living_area : null,
            'equity' => (float) $estate->equity,
            'monthly_rent' => (float) $estate->monthly_rent,
            'monthly_costs' => (float) $estate->monthly_costs,
            'notes' => $estate->notes,
            'purchase' => $summary['purchase'],
            'renovation' => $summary['renovation'],
            'funding' => $summary['funding'],
            'monthly' => $summary['monthly'],
            'yield' => $summary['yield'],
            'price_per_sqm' => $summary['price_per_sqm'],
            'paid_total' => $summary['paid_total'],
            'items' => $items,
            'invoices' => $invoices,
        ];
    }
}
