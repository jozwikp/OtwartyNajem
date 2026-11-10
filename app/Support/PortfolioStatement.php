<?php

namespace App\Support;

use App\Enums\BillStatus;
use App\Enums\ChargeType;
use App\Enums\LedgerKind;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\RecurringCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly income and expenses across all of a user's apartments, on two bases:
 *
 * - accrual: rent charged to tenants, and approved bills, counted in the month they belong to
 *   (the charge's period, the bill's billing period) — what is owed, regardless of when paid.
 * - cash: money that actually moved. Income is real payments received, counted in the month
 *   they were booked. There's no record of when a bill is actually paid to its supplier, so its
 *   cash expense is approximated by the month the bill was approved (the closest date tracked).
 */
class PortfolioStatement
{
    private const ROW = ['accrual_income' => 0, 'accrual_expense' => 0, 'cash_income' => 0, 'cash_expense' => 0];

    /** @var Collection<string, Collection<string, array{accrual_income: int, accrual_expense: int, cash_income: int, cash_expense: int}>> keyed by currency, then "Y-m" */
    private Collection $byCurrency;

    public function __construct(User $user)
    {
        $this->byCurrency = collect();

        $leaseIds = Lease::query()->whereHas('apartment', fn ($query) => $query->ownedBy($user))->pluck('id');
        $rentChargeIds = RecurringCharge::query()->whereIn('lease_id', $leaseIds)->where('type', ChargeType::Rent)->pluck('id');

        $accrualIncome = LedgerEntry::query()
            ->whereIn('lease_id', $leaseIds)
            ->where('kind', LedgerKind::Charge)
            ->where('source_type', RecurringCharge::class)
            ->whereIn('source_id', $rentChargeIds)
            ->get(['period', 'amount', 'currency']);

        foreach ($accrualIncome as $entry) {
            $this->add($entry->currency, $entry->period->format('Y-m'), accrualIncome: $entry->amount);
        }

        $cashIncome = LedgerEntry::query()
            ->whereIn('lease_id', $leaseIds)
            ->where('kind', LedgerKind::Payment)
            ->get(['booked_on', 'amount', 'currency']);

        foreach ($cashIncome as $entry) {
            $this->add($entry->currency, $entry->booked_on->format('Y-m'), cashIncome: $entry->amount);
        }

        $apartmentIds = Apartment::ownedBy($user)->pluck('id');

        $bills = Bill::query()
            ->whereIn('apartment_id', $apartmentIds)
            ->where('status', BillStatus::Approved)
            ->whereNotNull('period_from')
            ->get(['period_from', 'approved_at', 'total_amount', 'currency']);

        foreach ($bills as $bill) {
            $currency = $bill->currency ?? Currencies::DEFAULT;
            $amount = (int) $bill->total_amount;

            $this->add($currency, $bill->period_from->format('Y-m'), accrualExpense: $amount);

            if ($bill->approved_at) {
                $this->add($currency, $bill->approved_at->format('Y-m'), cashExpense: $amount);
            }
        }
    }

    private function add(string $currency, string $month, int $accrualIncome = 0, int $accrualExpense = 0, int $cashIncome = 0, int $cashExpense = 0): void
    {
        $months = $this->byCurrency->get($currency) ?? collect();
        $row = $months->get($month) ?? self::ROW;
        $row['accrual_income'] += $accrualIncome;
        $row['accrual_expense'] += $accrualExpense;
        $row['cash_income'] += $cashIncome;
        $row['cash_expense'] += $cashExpense;

        $this->byCurrency->put($currency, $months->put($month, $row));
    }

    /**
     * Currency codes that have any data, PLN (if present) first, then alphabetically.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_values($this->byCurrency->keys()
            ->sortBy(fn (string $currency) => $currency === Currencies::DEFAULT ? '' : $currency)
            ->all());
    }

    /**
     * Years with any data, newest first (always includes the current year).
     *
     * @return list<int>
     */
    public function years(): array
    {
        return array_values($this->byCurrency
            ->flatMap(fn (Collection $months) => $months->keys())
            ->map(fn (string $month) => (int) substr($month, 0, 4))
            ->push(CarbonImmutable::today()->year)
            ->unique()
            ->sortDesc()
            ->all());
    }

    /**
     * The 12 months of the year for one currency, in calendar order (January first).
     *
     * @return Collection<int, array{month: CarbonImmutable, accrual_income: int, accrual_expense: int, accrual_balance: int, cash_income: int, cash_expense: int, cash_balance: int}>
     */
    public function monthsOf(string $currency, int $year): Collection
    {
        $months = $this->byCurrency->get($currency) ?? collect();

        return collect(range(1, 12))->map(function (int $month) use ($months, $year) {
            $row = $months->get(sprintf('%04d-%02d', $year, $month)) ?? self::ROW;
            $accrualIncome = (int) $row['accrual_income'];
            $accrualExpense = (int) $row['accrual_expense'];
            $cashIncome = (int) $row['cash_income'];
            $cashExpense = (int) $row['cash_expense'];

            return [
                'month' => CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month)),
                'accrual_income' => $accrualIncome,
                'accrual_expense' => $accrualExpense,
                'accrual_balance' => $accrualIncome - $accrualExpense,
                'cash_income' => $cashIncome,
                'cash_expense' => $cashExpense,
                'cash_balance' => $cashIncome - $cashExpense,
            ];
        });
    }

    /**
     * @return array{accrual_income: int, accrual_expense: int, accrual_balance: int, cash_income: int, cash_expense: int, cash_balance: int}
     */
    public function totalsOf(string $currency, int $year): array
    {
        $months = $this->monthsOf($currency, $year);

        $accrualIncome = (int) $months->sum('accrual_income');
        $accrualExpense = (int) $months->sum('accrual_expense');
        $cashIncome = (int) $months->sum('cash_income');
        $cashExpense = (int) $months->sum('cash_expense');

        return [
            'accrual_income' => $accrualIncome,
            'accrual_expense' => $accrualExpense,
            'accrual_balance' => $accrualIncome - $accrualExpense,
            'cash_income' => $cashIncome,
            'cash_expense' => $cashExpense,
            'cash_balance' => $cashIncome - $cashExpense,
        ];
    }
}
