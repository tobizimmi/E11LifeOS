<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateRentPayment;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Letting: rent received per tenant and month, matching of bank bookings and the utility
 * statement (Nebenkostenabrechnung) per tenant.
 */
class RentalAccounting
{
    /**
     * Expected and received rent per tenant and month of a year.
     *
     * @return array{tenants: list<array{tenant: RealEstateTenant, months: array<int, array{expected: ?float, paid: float, status: string}>, expected: float, paid: float, arrears: float}>, expected: float, paid: float, arrears: float}
     */
    public static function rentOverview(RealEstate $realEstate, int $year): array
    {
        $start = Carbon::create($year, 1, 1);
        $tenants = $realEstate->tenants()->with('payments')->get()
            ->filter(fn (RealEstateTenant $tenant) => $tenant->daysIn($start, $start->copy()->endOfYear()) > 0)
            ->sortBy(fn (RealEstateTenant $tenant) => [$tenant->unit ?? '', $tenant->move_in_on->toDateString()]);
        $rows = [];

        foreach ($tenants as $tenant) {
            $months = [];

            for ($month = 1; $month <= 12; $month++) {
                $date = Carbon::create($year, $month, 1);
                $paid = round((float) $tenant->payments->filter(fn (RealEstateRentPayment $payment) => $payment->month->isSameMonth($date))->sum('amount'), 2);
                $expected = $tenant->livesInMonth($date) ? $tenant->monthlyTotal() : null;
                $due = $date->copy()->day(min(28, max(1, $tenant->due_day)));

                $months[$month] = [
                    'expected' => $expected,
                    'paid' => $paid,
                    'status' => match (true) {
                        $expected === null => $paid > 0 ? 'paid' : 'none',
                        $paid >= $expected - 0.01 => 'paid',
                        $due->isFuture() => 'future',
                        $paid > 0 => 'partial',
                        default => 'open',
                    },
                ];
            }

            $expectedSoFar = collect($months)->filter(fn (array $row, int $month) => $row['expected'] !== null && Carbon::create($year, $month, 1)->day(min(28, max(1, $tenant->due_day)))->isPast())->sum('expected');
            $paidSoFar = collect($months)->filter(fn (array $row, int $month) => Carbon::create($year, $month, 1)->day(min(28, max(1, $tenant->due_day)))->isPast())->sum('paid');

            $rows[] = [
                'tenant' => $tenant,
                'months' => $months,
                'expected' => round((float) collect($months)->sum('expected'), 2),
                'paid' => round((float) collect($months)->sum('paid'), 2),
                'arrears' => round(max(0, $expectedSoFar - $paidSoFar), 2),
            ];
        }

        return [
            'tenants' => $rows,
            'expected' => round((float) collect($rows)->sum('expected'), 2),
            'paid' => round((float) collect($rows)->sum('paid'), 2),
            'arrears' => round((float) collect($rows)->sum('arrears'), 2),
        ];
    }

    /**
     * Rent (incl. advance payments) received in a year – the income for Anlage V.
     */
    public static function receivedIn(RealEstate $realEstate, int $year): float
    {
        return round((float) RealEstateRentPayment::whereIn('tenant_id', $realEstate->tenants()->select('id'))
            ->whereBetween('paid_on', [$year.'-01-01', $year.'-12-31'])->sum('amount'), 2);
    }

    /**
     * Assigns incoming bank bookings of the user to open months: same amount (total or cold
     * rent), booked from a week before the month until its end, with the tenant's reference in
     * payer or purpose. Returns the number of assigned payments.
     */
    public static function matchPayments(RealEstate $realEstate, User $user, int $householdId, int $year): int
    {
        $assigned = 0;
        $used = RealEstateRentPayment::whereNotNull('transaction_id')->pluck('transaction_id')->all();

        foreach (self::rentOverview($realEstate, $year)['tenants'] as $row) {
            $tenant = $row['tenant'];
            $reference = mb_strtolower($tenant->reference());

            if (mb_strlen($reference) < 3) {
                continue;
            }

            foreach ($row['months'] as $month => $status) {
                if (! in_array($status['status'], ['open', 'partial', 'future'], true) || $status['paid'] > 0) {
                    continue;
                }

                $date = Carbon::create($year, $month, 1);

                if ($date->isFuture() && ! $date->isCurrentMonth()) {
                    continue;
                }

                $match = Transaction::where('user_id', $user->id)->where('household_id', $householdId)
                    ->whereIn('amount', array_unique([$tenant->monthlyTotal(), $tenant->cold_rent]))
                    ->whereBetween('booked_on', [$date->copy()->subDays(7)->toDateString(), $date->copy()->endOfMonth()->toDateString()])
                    ->whereNotIn('id', $used)
                    ->orderBy('booked_on')->get()
                    ->first(fn (Transaction $transaction) => str_contains(mb_strtolower($transaction->counterparty.' '.$transaction->purpose), $reference));

                if ($match) {
                    RealEstateRentPayment::create([
                        'tenant_id' => $tenant->id,
                        'month' => $date->toDateString(),
                        'amount' => (float) $match->amount,
                        'paid_on' => $match->booked_on,
                        'transaction_id' => $match->id,
                    ]);
                    $used[] = $match->id;
                    $assigned++;
                }
            }
        }

        return $assigned;
    }

    /**
     * The utility statement per tenant: share of every cost line by its key (weighted by the
     * days lived there), the advance payments of the period and the balance (positive = the
     * tenant pays extra, negative = refund).
     *
     * @return array{period_days: int, total: float, tenants: list<array{tenant: RealEstateTenant, days: int, lines: list<array{label: string, key: string, total: float, share: float, amount: float}>, costs: float, advances: float, balance: float}>, landlord: float}
     */
    public static function utilityStatement(RealEstateUtilityStatement $statement, ?Collection $tenants = null): array
    {
        $start = $statement->period_start;
        $end = $statement->period_end;
        $periodDays = (int) $start->diffInDays($end) + 1;
        $tenants = ($tenants ?? $statement->realEstate->tenants()->get())
            ->filter(fn (RealEstateTenant $tenant) => $tenant->daysIn($start, $end) > 0)->values();
        $totalArea = (float) ($statement->total_area ?: $statement->realEstate->living_area ?: $tenants->sum('area'));
        $totalUnits = max(1, (int) ($statement->total_units ?: $tenants->pluck('unit')->filter()->unique()->count() ?: 1));
        $personDays = (float) $tenants->sum(fn (RealEstateTenant $tenant) => $tenant->persons * $tenant->daysIn($start, $end));
        $rows = [];

        foreach ($tenants as $tenant) {
            $days = $tenant->daysIn($start, $end);
            $time = $days / $periodDays;
            $area = (float) ($tenant->area ?: ($totalArea / $totalUnits));
            $shares = [
                'area' => $totalArea > 0 ? $area / $totalArea * $time : 0,
                'persons' => $personDays > 0 ? $tenant->persons * $days / $personDays : 0,
                'units' => $time / $totalUnits,
            ];
            $lines = collect($statement->costs ?? [])->map(function (array $cost) use ($shares) {
                $key = in_array($cost['key'] ?? '', RealEstateUtilityStatement::KEYS, true) ? $cost['key'] : 'area';
                $total = round((float) ($cost['amount'] ?? 0), 2);

                return ['label' => (string) ($cost['label'] ?? ''), 'key' => $key, 'total' => $total, 'share' => round($shares[$key] * 100, 2), 'amount' => round($total * $shares[$key], 2)];
            })->values()->all();
            $costs = round((float) collect($lines)->sum('amount'), 2);
            $advances = self::advancesIn($tenant, $start, $end);

            $rows[] = ['tenant' => $tenant, 'days' => $days, 'lines' => $lines, 'costs' => $costs, 'advances' => $advances, 'balance' => round($costs - $advances, 2)];
        }

        $total = $statement->totalCosts();

        return [
            'period_days' => $periodDays,
            'total' => $total,
            'tenants' => $rows,
            // Vacancy and not allocated parts stay with the landlord.
            'landlord' => round($total - collect($rows)->sum('costs'), 2),
        ];
    }

    /**
     * Advance payments for utilities owed in the period (pro rata for partial months).
     */
    private static function advancesIn(RealEstateTenant $tenant, Carbon $start, Carbon $end): float
    {
        $sum = 0.0;

        for ($month = $start->copy()->startOfMonth(); $month->lte($end); $month->addMonth()) {
            $from = $month->copy()->max($start);
            $to = $month->copy()->endOfMonth()->min($end);
            $days = $tenant->daysIn($from, $to);
            $sum += $tenant->utilities_advance * $days / $month->daysInMonth;
        }

        return round($sum, 2);
    }
}
