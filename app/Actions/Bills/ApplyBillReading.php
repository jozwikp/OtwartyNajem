<?php

namespace App\Actions\Bills;

use App\Enums\BillCategory;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fills the bill with what the AI read, after checking every value.
 */
class ApplyBillReading
{
    /**
     * @param  array<string, mixed>  $result
     * @param  Collection<int, int>  $allowedApartmentIds
     */
    public function handle(Bill $bill, array $result, Collection $allowedApartmentIds): void
    {
        $apartmentId = is_int($result['apartment_id'] ?? null) && $allowedApartmentIds->contains($result['apartment_id'])
            ? $result['apartment_id']
            : null;

        $amount = is_numeric($result['amount_to_pay'] ?? null) ? (int) round(((float) $result['amount_to_pay']) * 100) : null;
        if ($amount !== null && $amount < 0) {
            $amount = null;
        }

        $currency = strtoupper((string) ($result['currency'] ?? ''));

        $bill->forceFill([
            'apartment_id' => $apartmentId,
            'category' => BillCategory::tryFrom((string) ($result['category'] ?? '')) ?? BillCategory::Other,
            'supplier' => $this->text($result['supplier'] ?? null, 100),
            'invoice_number' => $this->text($result['invoice_number'] ?? null, 100),
            'issued_on' => $this->date($result['issued_on'] ?? null),
            'period_from' => $this->date($result['period_from'] ?? null),
            'period_to' => $this->date($result['period_to'] ?? null),
            'due_on' => $this->date($result['due_on'] ?? null),
            'currency' => in_array($currency, Currencies::codes(), true) ? $currency : null,
            'total_amount' => $amount,
            'tenant_amount' => $amount,
            'ai_result' => $result,
            'ai_error' => null,
            'status' => BillStatus::Review,
            'processed_at' => now(),
        ]);

        // Missing period: assume the month before the invoice date.
        if ($bill->issued_on && (! $bill->period_from || ! $bill->period_to)) {
            $bill->period_from ??= $bill->issued_on->subMonthNoOverflow()->startOfMonth();
            $bill->period_to ??= $bill->issued_on->subMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        if ($bill->period_from && $bill->period_to && $bill->period_from->gt($bill->period_to)) {
            [$bill->period_from, $bill->period_to] = [$bill->period_to, $bill->period_from];
        }

        $bill->lease()->associate(SyncBillCharge::leaseFor($bill));
        $bill->currency ??= $bill->lease?->currency ?? Currencies::DEFAULT;

        if (($result['is_bill'] ?? true) === false) {
            $bill->status = BillStatus::Failed;
            $bill->ai_error = __('Ten dokument nie wygląda na fakturę ani rachunek.').(filled($result['notes'] ?? null) ? ' '.$result['notes'] : '');
        }
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
