<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateHandover;
use App\Models\RealEstateRentPayment;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Support\Addons;
use App\Support\RealEstate\RentalAccounting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Letting of a property: tenants and leases (index rent, tenant portal), rent received (matched
 * with bank bookings, payment reminders), the yearly utility statement, handover protocols and
 * applicants with their self-disclosure.
 */
new class extends Component
{
    use InteractsWithHousehold, WithFileUploads;

    public const SECTIONS = ['tenants', 'rent', 'utilities', 'handovers', 'applicants'];

    private const MAX_PHOTOS = 12;

    #[Locked]
    public int $realEstateId;

    #[Url(as: 'rental', except: 'tenants')]
    public string $section = 'tenants';

    #[Url(as: 'rent_year', except: 0)]
    public int $year = 0;

    public ?int $editingTenantId = null;

    public bool $tenantFormOpen = false;

    /**
     * @var array<string, mixed>
     */
    public array $tenant = [];

    /**
     * @var array{landlord_name: string, landlord_address: string, landlord_iban: string}
     */
    public array $landlord = [];

    public ?string $message = null;

    /**
     * @var array{period_start: string, period_end: string, total_area: string, total_units: string, notes: string, costs: list<array{type: string, label: string, amount: string, key: string}>}
     */
    public array $statement = [];

    public string $applicantStatus = '';

    public ?int $openApplicantId = null;

    /**
     * 'list' or 'board' (one column per status, drag cards between them).
     */
    public string $applicantView = 'list';

    public string $applicantIntro = '';

    public string $applicantRent = '';

    /**
     * Current consumer price index entered per tenant (index rent calculator).
     *
     * @var array<int, string>
     */
    public array $indexCurrent = [];

    /**
     * @var array<int, string>
     */
    public array $indexMonth = [];

    public bool $handoverFormOpen = false;

    public ?int $editingHandoverId = null;

    /**
     * @var array{tenant_id: string, kind: string, handed_over_on: string, notes: string, meters: list<array{label: string, number: string, reading: string}>, keys: list<array{label: string, count: string}>, rooms: list<array{label: string, ok: bool, notes: string}>}
     */
    public array $handover = [];

    /**
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $handoverPhotos = [];

    public function mount(int $realEstateId): void
    {
        $this->resolveHousehold();
        $realEstate = $this->realEstate($realEstateId);
        $this->realEstateId = $realEstate->id;
        $this->section = in_array($this->section, self::SECTIONS, true) ? $this->section : 'tenants';
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : (int) now()->year;
        $this->landlord = [
            'landlord_name' => (string) ($realEstate->landlord_name ?? Auth::user()->name),
            'landlord_address' => (string) $realEstate->landlord_address,
            'landlord_iban' => (string) $realEstate->landlord_iban,
        ];
        $this->applicantIntro = (string) $realEstate->applicant_intro;
        $this->applicantRent = $realEstate->applicant_rent ? number_format($realEstate->applicant_rent, 2, ',', '') : '';
        $this->resetTenant();
        $this->loadStatement();
    }

    private function realEstate(?int $id = null): RealEstate
    {
        return RealEstate::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id ?? $this->realEstateId) ?? abort(404);
    }

    private function findTenant(int $id): RealEstateTenant
    {
        return $this->realEstate()->tenants()->find($id) ?? abort(404);
    }

    private function findApplicant(int $id): RealEstateApplicant
    {
        return $this->realEstate()->applicants()->find($id) ?? abort(404);
    }

    /**
     * Parses "1.234,50" or "1234.50" into a number string for validation.
     */
    private static function number(mixed $value): string
    {
        $value = str_replace(['€', ' '], '', trim((string) $value));

        if (str_contains($value, ',')) {
            return str_replace(['.', ','], ['', '.'], $value);
        }

        return preg_match('/^\d{1,3}(\.\d{3})+$/', $value) ? str_replace('.', '', $value) : $value;
    }

    public function updatedSection(): void
    {
        $this->section = in_array($this->section, self::SECTIONS, true) ? $this->section : 'tenants';
        $this->message = null;
    }

    public function updatedYear(): void
    {
        $this->year = max(2000, min(2100, $this->year));
        $this->loadStatement();
    }

    // ── Landlord ─────────────────────────────────────────────────────────

    public function saveLandlord(): void
    {
        $this->landlord['landlord_iban'] = strtoupper(str_replace(' ', '', (string) $this->landlord['landlord_iban']));
        $data = $this->validate([
            'landlord.landlord_name' => ['required', 'string', 'max:255'],
            'landlord.landlord_address' => ['nullable', 'string', 'max:255'],
            'landlord.landlord_iban' => ['nullable', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/'],
        ], attributes: ['landlord.landlord_iban' => 'IBAN'])['landlord'];

        $this->realEstate()->update(array_map(fn ($value) => $value === '' ? null : $value, $data));
        $this->message = __('Saved.');
    }

    // ── Tenants ──────────────────────────────────────────────────────────

    private function resetTenant(): void
    {
        $realEstate = $this->realEstate();
        $this->editingTenantId = null;
        $this->tenant = [
            'unit' => '', 'name' => '', 'email' => '', 'phone' => '', 'previous_address' => '', 'persons' => '1', 'area' => $realEstate->living_area ? (string) $realEstate->living_area : '',
            'move_in_on' => today()->addMonthNoOverflow()->startOfMonth()->toDateString(), 'move_out_on' => '',
            'cold_rent' => $realEstate->monthly_rent ? (string) $realEstate->monthly_rent : '', 'utilities_advance' => '', 'deposit' => '', 'deposit_paid_on' => '',
            'payment_reference' => '', 'lease_end_on' => '', 'notice_months' => '3', 'rent_adjustment' => 'none', 'index_base' => '', 'index_base_month' => '', 'due_day' => '3', 'special_terms' => '', 'notes' => '',
        ];
    }

    public function newTenant(): void
    {
        $this->resetValidation();
        $this->resetTenant();
        $this->tenantFormOpen = true;
    }

    public function editTenant(int $id): void
    {
        $tenant = $this->findTenant($id);
        $this->resetValidation();
        $this->editingTenantId = $tenant->id;
        $this->tenant = collect($tenant->only(array_keys($this->tenant)))
            ->map(fn ($value) => $value instanceof Carbon ? $value->toDateString() : ($value === null ? '' : (string) $value))->all();
        $this->tenantFormOpen = true;
    }

    public function cancelTenant(): void
    {
        $this->tenantFormOpen = false;
        $this->resetTenant();
    }

    public function saveTenant(): void
    {
        foreach (['cold_rent', 'utilities_advance', 'deposit', 'area', 'index_base'] as $field) {
            $this->tenant[$field] = self::number($this->tenant[$field] ?? '');
        }

        $data = $this->validate([
            'tenant.unit' => ['nullable', 'string', 'max:100'],
            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.email' => ['nullable', 'email', 'max:255'],
            'tenant.phone' => ['nullable', 'string', 'max:50'],
            'tenant.previous_address' => ['nullable', 'string', 'max:255'],
            'tenant.persons' => ['required', 'integer', 'min:1', 'max:20'],
            'tenant.area' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'tenant.move_in_on' => ['required', 'date'],
            'tenant.move_out_on' => ['nullable', 'date', 'after_or_equal:tenant.move_in_on'],
            'tenant.cold_rent' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'tenant.utilities_advance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // § 551 BGB: at most three monthly cold rents.
            'tenant.deposit' => ['nullable', 'numeric', 'min:0', fn ($attribute, $value, $fail) => (float) $value > 3 * (float) $this->tenant['cold_rent'] + 0.001 ? $fail(__('The deposit may be at most three monthly cold rents (§ 551 BGB).')) : null],
            'tenant.deposit_paid_on' => ['nullable', 'date'],
            'tenant.payment_reference' => ['nullable', 'string', 'max:255'],
            'tenant.lease_end_on' => ['nullable', 'date', 'after:tenant.move_in_on'],
            'tenant.notice_months' => ['required', 'integer', 'min:1', 'max:12'],
            'tenant.rent_adjustment' => ['required', Rule::in(RealEstateTenant::RENT_ADJUSTMENTS)],
            'tenant.index_base' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'tenant.index_base_month' => ['nullable', 'date_format:Y-m'],
            'tenant.due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'tenant.special_terms' => ['nullable', 'string', 'max:5000'],
            'tenant.notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: [
            'tenant.name' => __('Name'),
            'tenant.cold_rent' => __('Cold rent (€)'),
            'tenant.deposit' => __('Deposit (€)'),
            'tenant.move_in_on' => __('Move-in'),
            'tenant.move_out_on' => __('Move-out'),
        ])['tenant'];

        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
        $data['utilities_advance'] ??= 0;
        $data['deposit'] ??= 0;

        if ($this->editingTenantId) {
            $this->findTenant($this->editingTenantId)->update($data);
        } else {
            $this->realEstate()->tenants()->create($data + ['created_by' => Auth::id()]);
        }

        $this->tenantFormOpen = false;
        $this->resetTenant();
    }

    public function deleteTenant(int $id): void
    {
        $this->findTenant($id)->delete();
    }

    // ── Index rent & tenant portal ───────────────────────────────────────

    /**
     * Takes over the index adjustment: new cold rent, the current index becomes the new base.
     */
    public function applyIndexRent(int $tenantId): void
    {
        $tenant = $this->findTenant($tenantId);
        $this->validate(["indexCurrent.$tenantId" => ['required', 'numeric', 'min:1', 'max:1000'], "indexMonth.$tenantId" => ['nullable', 'date_format:Y-m']], attributes: ["indexCurrent.$tenantId" => __('Current index')]);
        $calculation = RentalAccounting::indexRent($tenant, (float) $this->indexCurrent[$tenantId]);

        if (! $calculation) {
            return;
        }

        if (! $calculation['allowed']) {
            $this->addError("indexCurrent.$tenantId", __('An index rent may change at most once a year – next on :date.', ['date' => $calculation['allowed_from']->format('d.m.Y')]));

            return;
        }

        $tenant->update([
            'cold_rent' => $calculation['new'],
            'index_base' => (float) $this->indexCurrent[$tenantId],
            'index_base_month' => ($this->indexMonth[$tenantId] ?? '') ?: now()->format('Y-m'),
            'last_rent_change_on' => $calculation['effective_on'],
        ]);
        unset($this->indexCurrent[$tenantId], $this->indexMonth[$tenantId]);
        $this->message = __('New cold rent :amount from :date saved.', ['amount' => number_format($calculation['new'], 2, ',', '.').' €', 'date' => $calculation['effective_on']->format('d.m.Y')]);
    }

    public function createPortalLink(int $tenantId): void
    {
        $this->findTenant($tenantId)->update(['portal_token' => Str::random(48)]);
    }

    public function revokePortalLink(int $tenantId): void
    {
        $this->findTenant($tenantId)->update(['portal_token' => null]);
    }

    // ── Handover protocols ───────────────────────────────────────────────

    private function findHandover(int $id): RealEstateHandover
    {
        return $this->realEstate()->handovers()->find($id) ?? abort(404);
    }

    public function newHandover(?int $tenantId = null, string $kind = 'move_in'): void
    {
        abort_unless(in_array($kind, RealEstateHandover::KINDS, true), 404);
        $tenant = $tenantId ? $this->findTenant($tenantId) : null;
        $this->resetValidation();
        $this->reset('handoverPhotos', 'editingHandoverId');
        $this->section = 'handovers';
        $this->handover = [
            'tenant_id' => $tenant ? (string) $tenant->id : '',
            'kind' => $kind,
            'handed_over_on' => ($kind === 'move_out' ? $tenant?->move_out_on : $tenant?->move_in_on)?->toDateString() ?? today()->toDateString(),
            'notes' => '',
            'meters' => collect([__('Electricity'), __('Gas'), __('Cold water'), __('Hot water'), __('Heating')])->map(fn (string $label) => ['label' => $label, 'number' => '', 'reading' => ''])->all(),
            'keys' => collect([__('Front door'), __('Flat door'), __('Letterbox'), __('Cellar')])->map(fn (string $label) => ['label' => $label, 'count' => ''])->all(),
            'rooms' => collect([__('Hallway'), __('Living room'), __('Bedroom'), __('Kitchen'), __('Bathroom')])->map(fn (string $label) => ['label' => $label, 'ok' => true, 'notes' => ''])->all(),
        ];
        $this->handoverFormOpen = true;
    }

    public function editHandover(int $id): void
    {
        $handover = $this->findHandover($id);
        $this->resetValidation();
        $this->reset('handoverPhotos');
        $this->editingHandoverId = $handover->id;
        $this->handover = [
            'tenant_id' => (string) ($handover->tenant_id ?? ''),
            'kind' => $handover->kind,
            'handed_over_on' => $handover->handed_over_on->toDateString(),
            'notes' => (string) $handover->notes,
            'meters' => $handover->meters ?? [],
            'keys' => $handover->keys ?? [],
            'rooms' => $handover->rooms ?? [],
        ];
        $this->handoverFormOpen = true;
    }

    public function addHandoverRow(string $group): void
    {
        $this->handover[$group][] = match ($group) {
            'meters' => ['label' => '', 'number' => '', 'reading' => ''],
            'keys' => ['label' => '', 'count' => ''],
            'rooms' => ['label' => '', 'ok' => true, 'notes' => ''],
            default => abort(404),
        };
    }

    public function removeHandoverRow(string $group, int $index): void
    {
        abort_unless(in_array($group, ['meters', 'keys', 'rooms'], true), 404);
        unset($this->handover[$group][$index]);
        $this->handover[$group] = array_values($this->handover[$group]);
    }

    public function cancelHandover(): void
    {
        $this->reset('handoverFormOpen', 'editingHandoverId', 'handover', 'handoverPhotos');
    }

    public function saveHandover(): void
    {
        $data = $this->validate([
            'handover.tenant_id' => ['nullable', Rule::exists('real_estate_tenants', 'id')->where('real_estate_id', $this->realEstateId)],
            'handover.kind' => ['required', Rule::in(RealEstateHandover::KINDS)],
            'handover.handed_over_on' => ['required', 'date'],
            'handover.notes' => ['nullable', 'string', 'max:5000'],
            'handover.meters' => ['array', 'max:30'],
            'handover.meters.*.label' => ['nullable', 'string', 'max:100'],
            'handover.meters.*.number' => ['nullable', 'string', 'max:100'],
            'handover.meters.*.reading' => ['nullable', 'string', 'max:50'],
            'handover.keys' => ['array', 'max:30'],
            'handover.keys.*.label' => ['nullable', 'string', 'max:100'],
            'handover.keys.*.count' => ['nullable', 'integer', 'min:0', 'max:99'],
            'handover.rooms' => ['array', 'max:40'],
            'handover.rooms.*.label' => ['nullable', 'string', 'max:100'],
            'handover.rooms.*.ok' => ['boolean'],
            'handover.rooms.*.notes' => ['nullable', 'string', 'max:1000'],
            'handoverPhotos' => ['array', 'max:'.self::MAX_PHOTOS],
            'handoverPhotos.*' => ['image', 'max:8192'],
        ], attributes: ['handover.handed_over_on' => __('Date')])['handover'];

        $keep = fn (array $rows) => array_values(array_filter($rows, fn (array $row) => trim((string) ($row['label'] ?? '')) !== ''));
        $attributes = [
            'tenant_id' => ($data['tenant_id'] ?? '') !== '' ? (int) $data['tenant_id'] : null,
            'kind' => $data['kind'],
            'handed_over_on' => $data['handed_over_on'],
            'notes' => ($data['notes'] ?? '') ?: null,
            'meters' => $keep($data['meters'] ?? []),
            'keys' => $keep($data['keys'] ?? []),
            'rooms' => array_map(fn (array $room) => ['label' => $room['label'], 'ok' => (bool) ($room['ok'] ?? true), 'notes' => (string) ($room['notes'] ?? '')], $keep($data['rooms'] ?? [])),
        ];

        $handover = $this->editingHandoverId
            ? tap($this->findHandover($this->editingHandoverId))->update($attributes)
            : $this->realEstate()->handovers()->create($attributes + ['created_by' => Auth::id()]);

        $photos = $handover->photos ?? [];

        foreach ($this->handoverPhotos as $photo) {
            if (count($photos) >= self::MAX_PHOTOS) {
                break;
            }

            $photos[] = $photo->store('handovers/'.$handover->id, RealEstateHandover::DISK);
        }

        $handover->update(['photos' => $photos]);
        $this->cancelHandover();
        $this->message = __('Handover protocol saved.');
    }

    public function removeHandoverPhoto(int $id, int $index): void
    {
        $handover = $this->findHandover($id);
        $photos = $handover->photos ?? [];
        abort_unless(isset($photos[$index]), 404);

        \Illuminate\Support\Facades\Storage::disk(RealEstateHandover::DISK)->delete($photos[$index]);
        unset($photos[$index]);
        $handover->update(['photos' => array_values($photos)]);
    }

    public function deleteHandover(int $id): void
    {
        $this->findHandover($id)->delete();
    }

    // ── Rent ─────────────────────────────────────────────────────────────

    /**
     * Records the expected rent of a month as received today – or removes the payments of
     * that month again.
     */
    public function togglePayment(int $tenantId, int $month): void
    {
        abort_unless($month >= 1 && $month <= 12, 404);
        $tenant = $this->findTenant($tenantId);
        $date = Carbon::create($this->year, $month, 1);
        $payments = $tenant->payments()->whereYear('month', $this->year)->whereMonth('month', $month)->get();

        if ($payments->isNotEmpty()) {
            $payments->each->delete();

            return;
        }

        abort_unless($tenant->livesInMonth($date), 422);
        RealEstateRentPayment::create(['tenant_id' => $tenant->id, 'month' => $date->toDateString(), 'amount' => $tenant->monthlyTotal(), 'paid_on' => today()->min($date->copy()->endOfMonth())->max($date->copy()->subDays(7))->toDateString()]);
    }

    public function matchPayments(): void
    {
        abort_unless(Auth::user()->canUseAddon(Addons::FINANCE), 403);
        $count = RentalAccounting::matchPayments($this->realEstate(), Auth::user(), (int) $this->householdId, $this->year);
        $this->message = $count > 0 ? trans_choice(':count rent payment found in your bank bookings.|:count rent payments found in your bank bookings.', $count) : __('No matching bank bookings found.');
    }

    // ── Utility statement ────────────────────────────────────────────────

    private function loadStatement(): void
    {
        $realEstate = $this->realEstate();
        $existing = $realEstate->utilityStatements()->where('year', $this->year)->first();
        $types = RealEstateUtilityStatement::costTypes();

        $this->statement = [
            'period_start' => $existing?->period_start->toDateString() ?? $this->year.'-01-01',
            'period_end' => $existing?->period_end->toDateString() ?? $this->year.'-12-31',
            'total_area' => (string) ($existing?->total_area ?? $realEstate->living_area ?? ''),
            'total_units' => (string) ($existing?->total_units ?? ''),
            'notes' => (string) ($existing?->notes ?? ''),
            'costs' => collect($existing?->costs ?? collect(['property_tax', 'water', 'street_cleaning', 'insurance'])->map(fn (string $type) => ['type' => $type, 'label' => $types[$type]['label'], 'amount' => '', 'key' => $types[$type]['key']])->all())
                ->map(fn (array $cost) => ['type' => (string) ($cost['type'] ?? 'other'), 'label' => (string) ($cost['label'] ?? ''), 'amount' => $cost['amount'] === '' || $cost['amount'] === null ? '' : number_format((float) $cost['amount'], 2, ',', ''), 'key' => (string) ($cost['key'] ?? 'area')])
                ->values()->all(),
        ];
    }

    public function addCost(string $type = 'other'): void
    {
        $types = RealEstateUtilityStatement::costTypes();
        $type = array_key_exists($type, $types) ? $type : 'other';
        $this->statement['costs'][] = ['type' => $type, 'label' => $types[$type]['label'], 'amount' => '', 'key' => $types[$type]['key']];
    }

    public function removeCost(int $index): void
    {
        unset($this->statement['costs'][$index]);
        $this->statement['costs'] = array_values($this->statement['costs']);
    }

    public function saveStatement(): void
    {
        foreach ($this->statement['costs'] as $index => $cost) {
            $this->statement['costs'][$index]['amount'] = self::number($cost['amount'] ?? '');
        }

        $this->statement['total_area'] = self::number($this->statement['total_area']);
        $data = $this->validate([
            'statement.period_start' => ['required', 'date'],
            'statement.period_end' => ['required', 'date', 'after:statement.period_start', fn ($attribute, $value, $fail) => Carbon::parse($this->statement['period_start'])->diffInDays(Carbon::parse($value)) > 366 ? $fail(__('The period may be at most twelve months (§ 556 BGB).')) : null],
            'statement.total_area' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'statement.total_units' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'statement.notes' => ['nullable', 'string', 'max:5000'],
            'statement.costs' => ['array', 'max:40'],
            'statement.costs.*.type' => ['required', Rule::in(array_keys(RealEstateUtilityStatement::costTypes()))],
            'statement.costs.*.label' => ['required', 'string', 'max:255'],
            'statement.costs.*.amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'statement.costs.*.key' => ['required', Rule::in(RealEstateUtilityStatement::KEYS)],
        ], attributes: ['statement.costs.*.amount' => __('Amount'), 'statement.costs.*.label' => __('Description')])['statement'];

        $data['costs'] = array_map(fn (array $cost) => ['type' => $cost['type'], 'label' => trim($cost['label']), 'amount' => round((float) $cost['amount'], 2), 'key' => $cost['key']], $data['costs']);
        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);

        $this->realEstate()->utilityStatements()->updateOrCreate(['year' => $this->year], $data);
        $this->loadStatement();
        $this->message = __('Utility statement saved.');
    }

    public function markStatementSent(): void
    {
        $statement = $this->realEstate()->utilityStatements()->where('year', $this->year)->first() ?? abort(404);
        $statement->update(['sent_on' => $statement->sent_on ? null : today()]);
    }

    // ── Applicants ───────────────────────────────────────────────────────

    public function openApplications(): void
    {
        $this->applicantRent = self::number($this->applicantRent);
        $this->validate(['applicantIntro' => ['nullable', 'string', 'max:2000'], 'applicantRent' => ['nullable', 'numeric', 'gt:0', 'max:100000']], attributes: ['applicantRent' => __('Total rent of the flat (€)')]);
        $realEstate = $this->realEstate();
        $realEstate->update([
            'applicant_token' => $realEstate->applicant_token ?? Str::random(32),
            'applicant_intro' => trim($this->applicantIntro) ?: null,
            'applicant_rent' => $this->applicantRent !== '' ? round((float) $this->applicantRent, 2) : null,
        ]);
        $this->message = __('The application form is online.');
    }

    public function closeApplications(): void
    {
        $this->realEstate()->update(['applicant_token' => null]);
        $this->message = __('The application form is closed. The old link no longer works.');
    }

    public function toggleApplicant(int $id): void
    {
        $this->openApplicantId = $this->openApplicantId === $id ? null : $this->findApplicant($id)->id;
    }

    public function setApplicantStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, RealEstateApplicant::STATUSES, true), 422);
        $this->findApplicant($id)->update(['status' => $status]);
    }

    /**
     * Opens an applicant from the board in the detailed list.
     */
    public function openApplicant(int $id): void
    {
        $this->openApplicantId = $this->findApplicant($id)->id;
        $this->applicantStatus = '';
        $this->applicantView = 'list';
    }

    public function rateApplicant(int $id, int $rating): void
    {
        abort_unless($rating >= 0 && $rating <= 5, 422);
        $this->findApplicant($id)->update(['rating' => $rating ?: null]);
    }

    public function saveApplicantNotes(int $id, string $notes): void
    {
        $this->findApplicant($id)->update(['notes' => mb_substr(trim($notes), 0, 5000) ?: null]);
    }

    public function deleteApplicant(int $id): void
    {
        $this->findApplicant($id)->delete();

        if ($this->openApplicantId === $id) {
            $this->openApplicantId = null;
        }
    }

    /**
     * Opens the tenant form prefilled from the applicant and marks the applicant accepted.
     */
    public function applicantToTenant(int $id): void
    {
        $applicant = $this->findApplicant($id);
        $applicant->update(['status' => 'accepted']);
        $this->resetValidation();
        $this->resetTenant();
        $this->tenant = array_merge($this->tenant, [
            'name' => $applicant->name,
            'email' => (string) $applicant->email,
            'phone' => (string) $applicant->phone,
            'previous_address' => (string) $applicant->current_address,
            'persons' => (string) max(1, $applicant->persons),
            'move_in_on' => $applicant->desired_move_in?->toDateString() ?? $this->tenant['move_in_on'],
        ]);
        $this->section = 'tenants';
        $this->tenantFormOpen = true;
    }

    public function with(): array
    {
        $realEstate = $this->realEstate();
        $tenants = $realEstate->tenants()->get();
        $statementModel = $this->section === 'utilities' ? $realEstate->utilityStatements()->where('year', $this->year)->first() : null;
        $applicants = $this->section === 'applicants'
            ? $realEstate->applicants()->when($this->applicantStatus !== '' && $this->applicantView === 'list', fn ($query) => $query->where('status', $this->applicantStatus))->get()
            : collect();
        $monthlyTotal = (float) $tenants->filter(fn (RealEstateTenant $tenant) => $tenant->isActiveOn(today()))->sum(fn (RealEstateTenant $tenant) => $tenant->monthlyTotal());

        return [
            'realEstate' => $realEstate,
            'activeTenants' => $tenants->filter(fn (RealEstateTenant $tenant) => $tenant->move_out_on === null || $tenant->move_out_on->gte(today()))->values(),
            'formerTenants' => $tenants->filter(fn (RealEstateTenant $tenant) => $tenant->move_out_on !== null && $tenant->move_out_on->lt(today()))->values(),
            'rent' => $this->section === 'rent' ? RentalAccounting::rentOverview($realEstate, $this->year) : null,
            'statementModel' => $statementModel,
            'statementResult' => $statementModel ? RentalAccounting::utilityStatement($statementModel->setRelation('realEstate', $realEstate), $tenants) : null,
            'applicants' => $applicants,
            'handovers' => $this->section === 'handovers' ? $realEstate->handovers()->with('tenant')->get() : collect(),
            'handoverKinds' => RealEstateHandover::kindLabels(),
            'indexCalculations' => collect($this->indexCurrent)->filter(fn ($value) => is_numeric($value) && (float) $value > 0)->mapWithKeys(fn ($value, $id) => [$id => ($tenant = $tenants->firstWhere('id', (int) $id)) ? RentalAccounting::indexRent($tenant, (float) $value) : null])->filter(),
            'applicantCounts' => $realEstate->applicants()->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'monthlyRentTotal' => $monthlyTotal,
            'years' => range((int) now()->year + 1, max(2000, min((int) now()->year - 5, (int) ($realEstate->purchase_date?->year ?? now()->year)))),
            'hasFinance' => Auth::user()->canUseAddon(Addons::FINANCE),
            'costTypes' => RealEstateUtilityStatement::costTypes(),
            'keyLabels' => RealEstateUtilityStatement::keyLabels(),
            'statusLabels' => RealEstateApplicant::statusLabels(),
            'adjustments' => RealEstateTenant::rentAdjustmentLabels(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $card = 'rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800';
@endphp

<div class="space-y-5">
    @unless ($realEstate->isLet())
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ __('This property is marked as lived in by yourself. Set the usage to “Let” or “Partly let” under Purchase & financing to include the rent in the tax estimate.') }}</p>
    @endunless

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-section-nav class="w-full md:w-auto" tab-property="section" :label="__('Letting')" :active="$section" :groups="['' => ['tenants' => ['👥', __('Tenants')], 'rent' => ['💶', __('Rent received')], 'utilities' => ['🧮', __('Utility statement')], 'handovers' => ['📋', __('Handovers')], 'applicants' => ['📝', __('Applicants')]]]" />
        @if (in_array($section, ['rent', 'utilities'], true))
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                {{ __('Year') }}
                <select wire:model.live="year" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    @if ($message)
        <p class="rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200" role="status">{{ $message }}</p>
    @endif

    {{-- ── Tenants ─────────────────────────────────────────────── --}}
    @if ($section === 'tenants')
        <section class="{{ $card }}" aria-labelledby="tenants-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 id="tenants-heading" class="font-semibold text-gray-900 dark:text-gray-100">👥 {{ __('Tenants') }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Current rent incl. advance payments: :amount per month', ['amount' => $euro($monthlyRentTotal)]) }}</p>
                </div>
                @unless ($tenantFormOpen)
                    <x-primary-button type="button" wire:click="newTenant">＋ {{ __('New tenant') }}</x-primary-button>
                @endunless
            </div>

            @if ($tenantFormOpen)
                <form wire:submit="saveTenant" class="mt-4 space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingTenantId ? __('Edit tenant') : __('New tenant') }}</h4>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-input-label for="tenant-name" :value="__('Name(s) of the tenant(s)')" />
                            <input id="tenant-name" wire:model="tenant.name" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-unit" :value="__('Unit (e.g. ground floor left)')" />
                            <input id="tenant-unit" wire:model="tenant.unit" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-email" :value="__('Email')" />
                            <input id="tenant-email" type="email" wire:model="tenant.email" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.email')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-phone" :value="__('Phone')" />
                            <input id="tenant-phone" wire:model="tenant.phone" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-previous" :value="__('Previous address')" />
                            <input id="tenant-previous" wire:model="tenant.previous_address" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-persons" :value="__('Persons')" />
                            <input id="tenant-persons" type="number" min="1" wire:model="tenant.persons" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.persons')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-area" :value="__('Living area (m²)')" />
                            <input id="tenant-area" inputmode="decimal" wire:model="tenant.area" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.area')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-move-in" :value="__('Move-in')" />
                            <input id="tenant-move-in" type="date" wire:model="tenant.move_in_on" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.move_in_on')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-cold-rent" :value="__('Cold rent (€)')" />
                            <input id="tenant-cold-rent" inputmode="decimal" wire:model="tenant.cold_rent" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.cold_rent')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-advance" :value="__('Advance for utilities (€)')" />
                            <input id="tenant-advance" inputmode="decimal" wire:model="tenant.utilities_advance" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.utilities_advance')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-due-day" :value="__('Due by day of month')" />
                            <input id="tenant-due-day" type="number" min="1" max="28" wire:model="tenant.due_day" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-deposit" :value="__('Deposit (€)')" />
                            <input id="tenant-deposit" inputmode="decimal" wire:model="tenant.deposit" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.deposit')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-deposit-paid" :value="__('Deposit received on')" />
                            <input id="tenant-deposit-paid" type="date" wire:model="tenant.deposit_paid_on" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-reference" :value="__('Reference in bank transfers')" />
                            <input id="tenant-reference" wire:model="tenant.payment_reference" placeholder="{{ $tenant['name'] ?: __('Name') }}" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-adjustment" :value="__('Rent adjustment')" />
                            <select id="tenant-adjustment" wire:model.live="tenant.rent_adjustment" class="{{ $input }}">
                                @foreach ($adjustments as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if (($tenant['rent_adjustment'] ?? '') === 'index')
                            <div>
                                <x-input-label for="tenant-index-base" :value="__('Consumer price index at the start (2020 = 100)')" />
                                <input id="tenant-index-base" inputmode="decimal" wire:model="tenant.index_base" placeholder="119,4" class="{{ $input }}">
                                <x-input-error :messages="$errors->get('tenant.index_base')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="tenant-index-month" :value="__('Index month')" />
                                <input id="tenant-index-month" type="month" wire:model="tenant.index_base_month" class="{{ $input }}">
                            </div>
                        @endif
                        <div>
                            <x-input-label for="tenant-lease-end" :value="__('Fixed term until (optional)')" />
                            <input id="tenant-lease-end" type="date" wire:model="tenant.lease_end_on" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.lease_end_on')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tenant-notice" :value="__('Notice period tenant (months)')" />
                            <input id="tenant-notice" type="number" min="1" max="12" wire:model="tenant.notice_months" class="{{ $input }}">
                        </div>
                        <div>
                            <x-input-label for="tenant-move-out" :value="__('Move-out')" />
                            <input id="tenant-move-out" type="date" wire:model="tenant.move_out_on" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('tenant.move_out_on')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label for="tenant-special" :value="__('Special agreements in the lease')" />
                            <textarea id="tenant-special" rows="3" wire:model="tenant.special_terms" class="{{ $input }}" placeholder="{{ __('e.g. kitchen is taken over, one parking space, pets allowed') }}"></textarea>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label for="tenant-notes" :value="__('Internal notes')" />
                            <textarea id="tenant-notes" rows="2" wire:model="tenant.notes" class="{{ $input }}"></textarea>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelTenant">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            <ul class="mt-4 space-y-3">
                @forelse ($activeTenants as $row)
                    <li wire:key="tenant-{{ $row->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row->name }}@if ($row->unit) <span class="text-sm font-normal text-gray-500">· {{ $row->unit }}</span>@endif</p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('since :date', ['date' => $row->move_in_on->format('d.m.Y')]) }}
                                    @if ($row->move_out_on) · {{ __('moves out :date', ['date' => $row->move_out_on->format('d.m.Y')]) }}@endif
                                    · {{ trans_choice(':count person|:count persons', $row->persons) }}@if ($row->area) · {{ number_format($row->area, 1, ',', '.') }} m²@endif
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':cold cold + :advance advance = :total', ['cold' => $euro($row->cold_rent), 'advance' => $euro($row->utilities_advance), 'total' => $euro($row->monthlyTotal())]) }}</p>
                                @if ($row->deposit > 0)
                                    <p @class(['text-xs', 'text-gray-500 dark:text-gray-400' => $row->deposit_paid_on, 'text-amber-700 dark:text-amber-300' => ! $row->deposit_paid_on])>
                                        {{ __('Deposit :amount', ['amount' => $euro($row->deposit)]) }} · {{ $row->deposit_paid_on ? __('received :date', ['date' => $row->deposit_paid_on->format('d.m.Y')]) : __('not received yet') }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <a href="{{ route('real-estate.lease', $row) }}" target="_blank" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">📄 {{ __('Lease (PDF)') }}</a>
                                <button type="button" wire:click="editTenant({{ $row->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
                                <button type="button" wire:click="deleteTenant({{ $row->id }})" wire:confirm="{{ __('Delete this tenant with all rent payments?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <button type="button" wire:click="newHandover({{ $row->id }}, 'move_in')" class="rounded-md bg-gray-100 px-2.5 py-1 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">📋 {{ __('Move-in protocol') }}</button>
                            <button type="button" wire:click="newHandover({{ $row->id }}, 'move_out')" class="rounded-md bg-gray-100 px-2.5 py-1 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">📋 {{ __('Move-out protocol') }}</button>
                            @if ($row->portal_token)
                                <span class="flex items-center gap-1 rounded-md bg-indigo-50 px-2.5 py-1 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200" x-data="{ copied: false }">
                                    🔗 <button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(route('rental.portal', $row->portal_token)) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" class="underline" x-text="copied ? @js(__('Copied!')) : @js(__('Copy tenant portal link'))"></button>
                                    <button type="button" wire:click="revokePortalLink({{ $row->id }})" wire:confirm="{{ __('Turn off the tenant portal link?') }}" class="ms-1 text-indigo-500" aria-label="{{ __('Turn off link') }}">×</button>
                                </span>
                            @else
                                <button type="button" wire:click="createPortalLink({{ $row->id }})" class="rounded-md bg-gray-100 px-2.5 py-1 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200" title="{{ __('A link for the tenant with the lease, sent statements and handover protocols – no login needed.') }}">🔗 {{ __('Create tenant portal link') }}</button>
                            @endif
                        </div>

                        @if ($row->rent_adjustment === 'index')
                            <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-900/40">
                                <p class="font-medium text-gray-800 dark:text-gray-200">📈 {{ __('Index rent') }}
                                    <span class="font-normal text-gray-500">· {{ $row->index_base ? __('base :index (:month)', ['index' => number_format($row->index_base, 1, ',', '.'), 'month' => $row->index_base_month ?: '–']) : __('enter the index at the start under Edit') }} · {{ __('next change possible from :date', ['date' => $row->nextIndexChangeOn()->format('d.m.Y')]) }}</span>
                                </p>
                                @if ($row->index_base)
                                    <div class="mt-2 flex flex-wrap items-end gap-2">
                                        <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Current index') }}
                                            <input inputmode="decimal" wire:model.live.debounce.400ms="indexCurrent.{{ $row->id }}" placeholder="124,1" class="{{ $input }} w-28">
                                        </label>
                                        <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Month') }}
                                            <input type="month" wire:model.live="indexMonth.{{ $row->id }}" class="{{ $input }}">
                                        </label>
                                        @if ($calc = $indexCalculations[$row->id] ?? null)
                                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ __(':old → :new (:percent %) from :date', ['old' => $euro($calc['old']), 'new' => $euro($calc['new']), 'percent' => number_format($calc['percent'], 2, ',', '.'), 'date' => $calc['effective_on']->format('d.m.Y')]) }}</p>
                                            <a href="{{ route('real-estate.index-rent', ['tenant' => $row->id, 'index' => $indexCurrent[$row->id], 'month' => ($indexMonth[$row->id] ?? '') ?: null]) }}" target="_blank" class="text-indigo-600 underline dark:text-indigo-400">📄 {{ __('Letter (PDF)') }}</a>
                                            <button type="button" wire:click="applyIndexRent({{ $row->id }})" wire:confirm="{{ __('Save the new cold rent?') }}" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Apply') }}</button>
                                        @endif
                                    </div>
                                    <x-input-error :messages="$errors->get('indexCurrent.'.$row->id)" class="mt-1" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Consumer price index (2020 = 100) from Destatis, table 61111-0002.') }}</p>
                                @endif
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No tenants yet. Add them here or take over an applicant.') }}</li>
                @endforelse
            </ul>

            @if ($formerTenants->isNotEmpty())
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer text-gray-600 dark:text-gray-300">{{ __('Former tenants (:count)', ['count' => $formerTenants->count()]) }}</summary>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($formerTenants as $row)
                            <li wire:key="former-tenant-{{ $row->id }}" class="flex items-center justify-between gap-2 py-2">
                                <span class="text-gray-700 dark:text-gray-300">{{ $row->name }} · {{ $row->move_in_on->format('m/Y') }}–{{ $row->move_out_on->format('m/Y') }}</span>
                                <button type="button" wire:click="editTenant({{ $row->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="{{ $card }}" aria-labelledby="landlord-heading">
            <h3 id="landlord-heading" class="font-semibold text-gray-900 dark:text-gray-100">🔑 {{ __('Landlord (for lease and statements)') }}</h3>
            <form wire:submit="saveLandlord" class="mt-3 grid gap-3 sm:grid-cols-4 sm:items-end">
                <div>
                    <x-input-label for="landlord-name" :value="__('Name')" />
                    <input id="landlord-name" wire:model="landlord.landlord_name" class="{{ $input }}">
                    <x-input-error :messages="$errors->get('landlord.landlord_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="landlord-address" :value="__('Address')" />
                    <input id="landlord-address" wire:model="landlord.landlord_address" class="{{ $input }}">
                </div>
                <div>
                    <x-input-label for="landlord-iban" :value="__('IBAN for the rent')" />
                    <input id="landlord-iban" wire:model="landlord.landlord_iban" class="{{ $input }}">
                    <x-input-error :messages="$errors->get('landlord.landlord_iban')" class="mt-1" />
                </div>
                <x-secondary-button type="submit" class="justify-center">{{ __('Save') }}</x-secondary-button>
            </form>
        </section>
    @endif

    {{-- ── Rent received ───────────────────────────────────────── --}}
    @if ($section === 'rent' && $rent)
        <section class="{{ $card }}" aria-labelledby="rent-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="rent-heading" class="font-semibold text-gray-900 dark:text-gray-100">💶 {{ __('Rent received :year', ['year' => $year]) }}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':paid of :expected received', ['paid' => $euro($rent['paid']), 'expected' => $euro($rent['expected'])]) }}
                        @if ($rent['arrears'] > 0) · <span class="font-medium text-red-700 dark:text-red-400">{{ __('Arrears: :amount', ['amount' => $euro($rent['arrears'])]) }}</span>@endif
                    </p>
                </div>
                @if ($hasFinance)
                    <x-secondary-button type="button" wire:click="matchPayments">🏦 {{ __('Match with bank bookings') }}</x-secondary-button>
                @endif
            </div>

            @if ($rent['tenants'] === [])
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No tenants in :year.', ['year' => $year]) }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-1 pe-3 font-medium">{{ __('Tenant') }}</th>
                                @foreach (range(1, 12) as $month)
                                    <th class="px-1 py-1 text-center font-medium">{{ \Illuminate\Support\Carbon::create($year, $month, 1)->translatedFormat('M') }}</th>
                                @endforeach
                                <th class="ps-3 py-1 text-right font-medium">{{ __('Arrears') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rent['tenants'] as $row)
                                <tr wire:key="rent-row-{{ $row['tenant']->id }}" class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-2 pe-3 whitespace-nowrap text-gray-900 dark:text-gray-100">{{ $row['tenant']->name }}<span class="block text-xs text-gray-500">{{ $euro($row['tenant']->monthlyTotal()) }}</span></td>
                                    @foreach ($row['months'] as $month => $cell)
                                        <td class="px-1 py-2 text-center">
                                            @if ($cell['status'] === 'none')
                                                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span>
                                            @else
                                                <button type="button" wire:click="togglePayment({{ $row['tenant']->id }}, {{ $month }})"
                                                    title="{{ $cell['paid'] > 0 ? __(':amount received', ['amount' => $euro($cell['paid'])]) : __('Mark as received') }}"
                                                    aria-label="{{ \Illuminate\Support\Carbon::create($year, $month, 1)->translatedFormat('F') }}: {{ ['paid' => __('received'), 'partial' => __('partly received'), 'open' => __('open'), 'future' => __('not due yet')][$cell['status']] }}"
                                                    @class(['inline-flex h-7 w-7 items-center justify-center rounded-md text-xs font-semibold',
                                                        'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' => $cell['status'] === 'paid',
                                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $cell['status'] === 'partial',
                                                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $cell['status'] === 'open',
                                                        'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300' => $cell['status'] === 'future'])>
                                                    {{ ['paid' => '✓', 'partial' => '½', 'open' => '!', 'future' => '–'][$cell['status']] }}
                                                </button>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td @class(['ps-3 py-2 text-right whitespace-nowrap', 'font-medium text-red-700 dark:text-red-400' => $row['arrears'] > 0, 'text-gray-500' => $row['arrears'] <= 0])>
                                        {{ $euro($row['arrears']) }}
                                        @if ($row['arrears'] > 0)
                                            <span class="block text-xs font-normal">
                                                <a href="{{ route('real-estate.dunning', ['tenant' => $row['tenant']->id, 'year' => $year, 'level' => 1]) }}" target="_blank" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Payment reminder') }}</a> ·
                                                <a href="{{ route('real-estate.dunning', ['tenant' => $row['tenant']->id, 'year' => $year, 'level' => 2]) }}" target="_blank" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Dunning letter') }}</a>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Click a month to mark the rent as received (or undo it). The received rent is used for Anlage V in the tax tab.') }}</p>
            @endif
        </section>
    @endif

    {{-- ── Handover protocols ──────────────────────────────────── --}}
    @if ($section === 'handovers')
        <section class="{{ $card }}" aria-labelledby="handovers-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 id="handovers-heading" class="font-semibold text-gray-900 dark:text-gray-100">📋 {{ __('Handover protocols') }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Meter readings, keys, the state of the rooms and photos at move-in and move-out – as PDF to sign.') }}</p>
                </div>
                @unless ($handoverFormOpen)
                    <x-primary-button type="button" wire:click="newHandover">＋ {{ __('New protocol') }}</x-primary-button>
                @endunless
            </div>

            @if ($handoverFormOpen)
                <form wire:submit="saveHandover" class="mt-4 space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Tenant') }}
                            <select wire:model="handover.tenant_id" class="{{ $input }}">
                                <option value="">–</option>
                                @foreach ($activeTenants->concat($formerTenants) as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Kind') }}
                            <select wire:model="handover.kind" class="{{ $input }}">
                                @foreach ($handoverKinds as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Date') }}
                            <input type="date" wire:model="handover.handed_over_on" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('handover.handed_over_on')" class="mt-1" />
                        </label>
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-800 dark:text-gray-200">⚡ {{ __('Meter readings') }}</legend>
                        @foreach ($handover['meters'] ?? [] as $index => $meter)
                            <div wire:key="meter-{{ $index }}" class="mt-1 grid grid-cols-[1fr_1fr_7rem_auto] gap-2">
                                <input wire:model="handover.meters.{{ $index }}.label" placeholder="{{ __('Meter') }}" aria-label="{{ __('Meter') }}" class="{{ $input }} mt-0">
                                <input wire:model="handover.meters.{{ $index }}.number" placeholder="{{ __('Meter number') }}" aria-label="{{ __('Meter number') }}" class="{{ $input }} mt-0">
                                <input wire:model="handover.meters.{{ $index }}.reading" inputmode="decimal" placeholder="{{ __('Reading') }}" aria-label="{{ __('Reading') }}" class="{{ $input }} mt-0">
                                <button type="button" wire:click="removeHandoverRow('meters', {{ $index }})" class="px-2 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addHandoverRow('meters')" class="mt-1 text-xs text-indigo-600 underline dark:text-indigo-400">＋ {{ __('Meter') }}</button>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-800 dark:text-gray-200">🔑 {{ __('Keys') }}</legend>
                        @foreach ($handover['keys'] ?? [] as $index => $key)
                            <div wire:key="key-{{ $index }}" class="mt-1 grid grid-cols-[1fr_6rem_auto] gap-2">
                                <input wire:model="handover.keys.{{ $index }}.label" placeholder="{{ __('Key') }}" aria-label="{{ __('Key') }}" class="{{ $input }} mt-0">
                                <input type="number" min="0" max="99" wire:model="handover.keys.{{ $index }}.count" placeholder="{{ __('Count') }}" aria-label="{{ __('Count') }}" class="{{ $input }} mt-0">
                                <button type="button" wire:click="removeHandoverRow('keys', {{ $index }})" class="px-2 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addHandoverRow('keys')" class="mt-1 text-xs text-indigo-600 underline dark:text-indigo-400">＋ {{ __('Key') }}</button>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-800 dark:text-gray-200">🚪 {{ __('Rooms') }}</legend>
                        @foreach ($handover['rooms'] ?? [] as $index => $room)
                            <div wire:key="room-{{ $index }}" class="mt-1 grid grid-cols-[1fr_auto_2fr_auto] items-center gap-2">
                                <input wire:model="handover.rooms.{{ $index }}.label" placeholder="{{ __('Room') }}" aria-label="{{ __('Room') }}" class="{{ $input }} mt-0">
                                <label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model="handover.rooms.{{ $index }}.ok" class="rounded-sm border-gray-300"> {{ __('OK') }}</label>
                                <input wire:model="handover.rooms.{{ $index }}.notes" placeholder="{{ __('Notes / defects') }}" aria-label="{{ __('Notes / defects') }}" class="{{ $input }} mt-0">
                                <button type="button" wire:click="removeHandoverRow('rooms', {{ $index }})" class="px-2 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addHandoverRow('rooms')" class="mt-1 text-xs text-indigo-600 underline dark:text-indigo-400">＋ {{ __('Room') }}</button>
                    </fieldset>

                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Further agreements') }}
                        <textarea rows="3" wire:model="handover.notes" class="{{ $input }}" placeholder="{{ __('e.g. wall in the bedroom is repainted by 15.10.') }}"></textarea>
                    </label>

                    <label class="block text-sm text-gray-700 dark:text-gray-300">📷 {{ __('Photos') }}
                        <input type="file" wire:model="handoverPhotos" multiple accept="image/*" capture="environment" class="mt-1 block w-full text-sm">
                        <x-input-error :messages="array_merge($errors->get('handoverPhotos'), $errors->get('handoverPhotos.*'))" class="mt-1" />
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <x-primary-button wire:loading.attr="disabled" wire:target="handoverPhotos,saveHandover">{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelHandover">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            <ul class="mt-4 space-y-3">
                @forelse ($handovers as $item)
                    <li wire:key="handover-{{ $item->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $handoverKinds[$item->kind] ?? $item->kind }} · {{ $item->handed_over_on->format('d.m.Y') }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $item->tenant?->name ?? '–' }} · {{ trans_choice(':count meter|:count meters', count($item->meters ?? [])) }} · {{ trans_choice(':count photo|:count photos', count($item->photos ?? [])) }}
                                    @if (collect($item->rooms ?? [])->contains(fn ($room) => ! ($room['ok'] ?? true))) · <span class="text-amber-700 dark:text-amber-300">{{ __('with defects') }}</span>@endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <a href="{{ route('real-estate.handover-pdf', $item) }}" target="_blank" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">📄 PDF</a>
                                <button type="button" wire:click="editHandover({{ $item->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
                                <button type="button" wire:click="deleteHandover({{ $item->id }})" wire:confirm="{{ __('Delete this protocol with its photos?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            </div>
                        </div>
                        @if (! empty($item->photos))
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($item->photos as $index => $photo)
                                    <div wire:key="handover-photo-{{ $item->id }}-{{ $index }}" class="group relative">
                                        <a href="{{ route('real-estate.handover-photo', [$item, $index]) }}" target="_blank"><img src="{{ route('real-estate.handover-photo', [$item, $index]) }}" alt="{{ __('Photo :number', ['number' => $index + 1]) }}" class="h-16 w-16 rounded-md object-cover" loading="lazy"></a>
                                        <button type="button" wire:click="removeHandoverPhoto({{ $item->id }}, {{ $index }})" wire:confirm="{{ __('Remove this photo?') }}" class="absolute -right-1 -top-1 hidden h-5 w-5 rounded-full bg-red-600 text-xs text-white group-hover:block" aria-label="{{ __('Remove') }}">×</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No handover protocols yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif

    {{-- ── Utility statement ───────────────────────────────────── --}}
    @if ($section === 'utilities')
        <section class="{{ $card }}" aria-labelledby="utilities-heading">
            <h3 id="utilities-heading" class="font-semibold text-gray-900 dark:text-gray-100">🧮 {{ __('Utility statement :year', ['year' => $year]) }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Enter the allocatable operating costs of the year (§ 2 BetrKV). They are split per tenant by the key and the days lived there.') }}</p>

            <form wire:submit="saveStatement" class="mt-4 space-y-4">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <x-input-label for="statement-start" :value="__('Period from')" />
                        <input id="statement-start" type="date" wire:model="statement.period_start" class="{{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="statement-end" :value="__('Period until')" />
                        <input id="statement-end" type="date" wire:model="statement.period_end" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('statement.period_end')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="statement-area" :value="__('Total living area (m²)')" />
                        <input id="statement-area" inputmode="decimal" wire:model="statement.total_area" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('statement.total_area')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="statement-units" :value="__('Number of units')" />
                        <input id="statement-units" type="number" min="1" wire:model="statement.total_units" class="{{ $input }}">
                    </div>
                </div>

                <div class="space-y-2">
                    @foreach ($statement['costs'] as $index => $cost)
                        <div wire:key="cost-{{ $index }}" class="grid gap-2 sm:grid-cols-[1fr_9rem_10rem_auto] sm:items-start">
                            <div>
                                <label class="sr-only" for="cost-label-{{ $index }}">{{ __('Description') }}</label>
                                <input id="cost-label-{{ $index }}" wire:model="statement.costs.{{ $index }}.label" class="{{ $input }}">
                                <x-input-error :messages="$errors->get('statement.costs.'.$index.'.label')" class="mt-1" />
                            </div>
                            <div>
                                <label class="sr-only" for="cost-amount-{{ $index }}">{{ __('Amount') }}</label>
                                <input id="cost-amount-{{ $index }}" inputmode="decimal" placeholder="0,00 €" wire:model="statement.costs.{{ $index }}.amount" class="{{ $input }}">
                                <x-input-error :messages="$errors->get('statement.costs.'.$index.'.amount')" class="mt-1" />
                            </div>
                            <div>
                                <label class="sr-only" for="cost-key-{{ $index }}">{{ __('Allocation key') }}</label>
                                <select id="cost-key-{{ $index }}" wire:model="statement.costs.{{ $index }}.key" class="{{ $input }}">
                                    @foreach ($keyLabels as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" wire:click="removeCost({{ $index }})" class="mt-2 text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select aria-label="{{ __('Add cost type') }}" x-data x-on:change="$wire.addCost($event.target.value); $event.target.value = ''" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">＋ {{ __('Add cost type') }}</option>
                        @foreach ($costTypes as $key => $type)
                            <option value="{{ $key }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                    <x-primary-button>{{ __('Save and calculate') }}</x-primary-button>
                </div>
            </form>
        </section>

        @if ($statementResult)
            <section class="{{ $card }}" aria-labelledby="statement-result-heading">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 id="statement-result-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Result per tenant') }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Total costs :total · stays with you (vacancy): :landlord', ['total' => $euro($statementResult['total']), 'landlord' => $euro($statementResult['landlord'])]) }}</p>
                    </div>
                    <button type="button" wire:click="markStatementSent" class="text-sm text-indigo-600 underline dark:text-indigo-400">
                        {{ $statementModel->sent_on ? __('Sent on :date – undo', ['date' => $statementModel->sent_on->format('d.m.Y')]) : __('Mark as sent') }}
                    </button>
                </div>
                @if (! $statementModel->sent_on && $statementModel->period_end->copy()->addYear()->isPast())
                    <p class="mt-2 text-sm text-red-700 dark:text-red-400">⚠ {{ __('The deadline for extra payments (12 months after the period, § 556 BGB) has passed.') }}</p>
                @endif
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($statementResult['tenants'] as $row)
                        <li wire:key="statement-tenant-{{ $row['tenant']->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['tenant']->name }}</p>
                                <p class="text-gray-600 dark:text-gray-300">{{ __(':days days · costs :costs · advance payments :advances', ['days' => $row['days'], 'costs' => $euro($row['costs']), 'advances' => $euro($row['advances'])]) }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span @class(['font-semibold', 'text-red-700 dark:text-red-400' => $row['balance'] > 0, 'text-green-700 dark:text-green-400' => $row['balance'] <= 0])>
                                    {{ $row['balance'] > 0 ? __('Extra payment :amount', ['amount' => $euro($row['balance'])]) : __('Refund :amount', ['amount' => $euro(-$row['balance'])]) }}
                                </span>
                                <a href="{{ route('real-estate.utility-statement', [$statementModel, $row['tenant']]) }}" target="_blank" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">📄 PDF</a>
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No tenants in this period.') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif
    @endif

    {{-- ── Applicants ──────────────────────────────────────────── --}}
    @if ($section === 'applicants')
        <section class="{{ $card }}" aria-labelledby="applications-heading">
            <h3 id="applications-heading" class="font-semibold text-gray-900 dark:text-gray-100">📝 {{ __('Application form with self-disclosure') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Share the link with interested parties (e.g. in your listing). Applicants fill in the self-disclosure online; you compare them here and take over the chosen one as tenant.') }}</p>
            <div class="mt-3 space-y-3">
                <div class="sm:w-64">
                    <x-input-label for="applicant-rent" :value="__('Total rent of the flat (€)')" />
                    <input id="applicant-rent" inputmode="decimal" wire:model="applicantRent" class="{{ $input }}">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Used to compare with the applicants’ income.') }}</p>
                    <x-input-error :messages="$errors->get('applicantRent')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="applicant-intro" :value="__('Text above the form (optional)')" />
                    <textarea id="applicant-intro" rows="2" wire:model="applicantIntro" class="{{ $input }}" placeholder="{{ __('e.g. 3-room flat, 78 m², available from 1 December, cold rent 950 €') }}"></textarea>
                </div>
                @if ($realEstate->applicant_token)
                    <div x-data="{ copied: false }" class="flex flex-wrap items-center gap-2">
                        <input readonly value="{{ route('rental.apply', $realEstate->applicant_token) }}" class="min-w-0 flex-1 rounded-md border-gray-300 bg-gray-50 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Link to the application form') }}">
                        <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(route('rental.apply', $realEstate->applicant_token)) }}); copied = true; setTimeout(() => copied = false, 2000)"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>✓</span></x-secondary-button>
                        <x-secondary-button type="button" wire:click="openApplications">{{ __('Save text') }}</x-secondary-button>
                        <x-danger-button type="button" wire:click="closeApplications">{{ __('Close form') }}</x-danger-button>
                    </div>
                @else
                    <x-primary-button type="button" wire:click="openApplications">{{ __('Put form online') }}</x-primary-button>
                @endif
                <a href="{{ route('real-estate.self-disclosure-form', $realEstate) }}" target="_blank" class="inline-block text-sm text-indigo-600 underline dark:text-indigo-400">📄 {{ __('Blank self-disclosure as PDF (for viewings)') }}</a>
            </div>
        </section>

        <section class="{{ $card }}" aria-labelledby="applicants-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="applicants-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Applicants') }}</h3>
                <div class="ms-auto inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900" role="group" aria-label="{{ __('View') }}">
                    @foreach (['list' => '☰ '.__('List'), 'board' => '▦ '.__('Board')] as $view => $viewLabel)
                        <button type="button" wire:click="$set('applicantView', '{{ $view }}')" aria-pressed="{{ $applicantView === $view ? 'true' : 'false' }}" @class(['rounded-md px-2 py-1', 'bg-white font-medium shadow-xs dark:bg-gray-700 dark:text-gray-100' => $applicantView === $view, 'text-gray-600 dark:text-gray-400' => $applicantView !== $view])>{{ $viewLabel }}</button>
                    @endforeach
                </div>
                <select @if ($applicantView === 'board') hidden @endif wire:model.live="applicantStatus" aria-label="{{ __('Status') }}" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">{{ __('All (:count)', ['count' => $applicantCounts->sum()]) }}</option>
                    @foreach ($statusLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }} ({{ $applicantCounts[$key] ?? 0 }})</option>
                    @endforeach
                </select>
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Rejected applicants are deleted automatically after six months (data protection).') }}</p>

            @if ($applicantView === 'board')
                <div class="mt-3 flex gap-3 overflow-x-auto pb-2">
                    @foreach ($statusLabels as $status => $label)
                        <div wire:key="applicant-column-{{ $status }}" class="flex w-56 shrink-0 flex-col rounded-xl bg-gray-50 p-2 dark:bg-gray-900/50">
                            <h4 class="flex items-center justify-between px-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }} <span>{{ $applicants->where('status', $status)->count() }}</span></h4>
                            <ul class="mt-2 flex min-h-[4rem] flex-1 flex-col gap-2" x-data x-sort="$wire.setApplicantStatus($item, @js($status))" x-sort:group="applicants" x-sort:config="{ animation: 150, ghostClass: 'opacity-40' }" aria-label="{{ $label }}">
                                @foreach ($applicants->where('status', $status) as $applicant)
                                    <li wire:key="applicant-card-{{ $applicant->id }}" x-sort:item="{{ $applicant->id }}" class="cursor-grab rounded-lg bg-white p-2 text-sm shadow-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                                        <button type="button" wire:click="openApplicant({{ $applicant->id }})" class="block w-full text-start">
                                            <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $applicant->name }}</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count person|:count persons', $applicant->persons) }}@if ($applicant->net_income) · {{ __(':amount net', ['amount' => $euro($applicant->net_income, 0)]) }}@endif</span>
                                            @if ($applicant->rating)
                                                <span class="block text-xs text-amber-400" aria-label="{{ trans_choice(':count star|:count stars', $applicant->rating) }}">{{ str_repeat('★', $applicant->rating) }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Drag the cards to change the status; click a card for the details.') }}</p>
            @else
            <ul class="mt-3 space-y-3">
                @forelse ($applicants as $applicant)
                    @php
                        $ratio = $applicant->rentToIncome((float) $realEstate->applicant_rent);
                    @endphp
                    <li wire:key="applicant-{{ $applicant->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <button type="button" wire:click="toggleApplicant({{ $applicant->id }})" class="min-w-0 text-start" aria-expanded="{{ $openApplicantId === $applicant->id ? 'true' : 'false' }}">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $applicant->name }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ trans_choice(':count person|:count persons', $applicant->persons) }}
                                    @if ($applicant->occupation) · {{ $applicant->occupation }}@endif
                                    @if ($applicant->net_income) · {{ __(':amount net', ['amount' => $euro($applicant->net_income, 0)]) }}@endif
                                    @if ($ratio !== null) · <span @class(['text-green-700 dark:text-green-400' => $ratio <= 35, 'text-amber-700 dark:text-amber-300' => $ratio > 35 && $ratio <= 45, 'text-red-700 dark:text-red-400' => $ratio > 45])>{{ __('rent :ratio % of income', ['ratio' => number_format($ratio, 0, ',', '.')]) }}</span>@endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $applicant->created_at->format('d.m.Y') }}@if ($applicant->desired_move_in) · {{ __('move-in from :date', ['date' => $applicant->desired_move_in->format('d.m.Y')]) }}@endif
                                    @if ($applicant->rent_arrears || $applicant->insolvency || $applicant->eviction) · <span class="text-red-700 dark:text-red-400">⚠ {{ __('negative information') }}</span>@endif
                                </p>
                            </button>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="flex" role="group" aria-label="{{ __('Rating') }}">
                                    @foreach (range(1, 5) as $star)
                                        <button type="button" wire:click="rateApplicant({{ $applicant->id }}, {{ $applicant->rating === $star ? 0 : $star }})" @class(['text-lg leading-none', 'text-amber-400' => $star <= (int) $applicant->rating, 'text-gray-300 dark:text-gray-600' => $star > (int) $applicant->rating]) aria-label="{{ trans_choice(':count star|:count stars', $star) }}">★</button>
                                    @endforeach
                                </div>
                                <select wire:change="setApplicantStatus({{ $applicant->id }}, $event.target.value)" aria-label="{{ __('Status') }}" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach ($statusLabels as $key => $label)
                                        <option value="{{ $key }}" @selected($applicant->status === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($openApplicantId === $applicant->id)
                            <dl class="mt-3 grid gap-x-6 gap-y-1 border-t border-gray-100 pt-3 text-sm sm:grid-cols-2 dark:border-gray-700">
                                @foreach ([
                                    __('Email') => $applicant->email,
                                    __('Phone') => $applicant->phone,
                                    __('Date of birth') => $applicant->birth_date?->format('d.m.Y'),
                                    __('Current address') => $applicant->current_address,
                                    __('Employer') => $applicant->employer,
                                    __('Employed since') => $applicant->employed_since?->format('m/Y'),
                                    __('Children') => $applicant->children,
                                    __('Pets') => $applicant->pets ?: __('none'),
                                    __('Smoker') => $applicant->smoker ? __('Yes') : __('No'),
                                    __('Current landlord') => $applicant->current_landlord,
                                    __('Rent arrears') => $applicant->rent_arrears ? __('Yes') : __('No'),
                                    __('Insolvency (last 5 years)') => $applicant->insolvency ? __('Yes') : __('No'),
                                    __('Eviction proceedings') => $applicant->eviction ? __('Yes') : __('No'),
                                    __('Credit report (SCHUFA) can be provided') => $applicant->credit_report ? __('Yes') : __('No'),
                                ] as $label => $value)
                                    <div class="flex justify-between gap-3 py-0.5"><dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="text-right text-gray-900 dark:text-gray-100">{{ $value ?: '–' }}</dd></div>
                                @endforeach
                            </dl>
                            @if ($applicant->message)
                                <p class="mt-3 whitespace-pre-line rounded-md bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">{{ $applicant->message }}</p>
                            @endif
                            <div class="mt-3">
                                <x-input-label for="applicant-notes-{{ $applicant->id }}" :value="__('Internal notes')" />
                                <textarea id="applicant-notes-{{ $applicant->id }}" rows="2" x-data x-on:change="$wire.saveApplicantNotes({{ $applicant->id }}, $event.target.value)" class="{{ $input }}">{{ $applicant->notes }}</textarea>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                <a href="{{ route('real-estate.applicant-pdf', $applicant) }}" target="_blank" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">📄 {{ __('Self-disclosure (PDF)') }}</a>
                                <a href="mailto:{{ $applicant->email }}" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">✉️ {{ __('Write email') }}</a>
                                <x-primary-button type="button" wire:click="applicantToTenant({{ $applicant->id }})">🔑 {{ __('Take over as tenant') }}</x-primary-button>
                                <button type="button" wire:click="deleteApplicant({{ $applicant->id }})" wire:confirm="{{ __('Delete this applicant and the self-disclosure?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No applications yet.') }}</li>
                @endforelse
            </ul>
            @endif
        </section>
    @endif
</div>
