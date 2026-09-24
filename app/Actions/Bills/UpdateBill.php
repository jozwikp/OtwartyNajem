<?php

namespace App\Actions\Bills;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves corrections to a bill. An approved bill keeps the tenant's charge up to date
 * (amount, lease – also when the bill is moved to another apartment).
 */
class UpdateBill
{
    public function __construct(protected SyncBillCharge $syncBillCharge) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Bill $bill, User $user, array $data, bool $approve = false): void
    {
        DB::transaction(function () use ($bill, $user, $data, $approve) {
            $bill->forceFill($data);

            if ($bill->isDirty(['apartment_id', 'period_from', 'period_to', 'issued_on']) || $approve) {
                $bill->lease()->associate(SyncBillCharge::leaseFor($bill));
            }

            if ($bill->status === BillStatus::Failed && $bill->category && $bill->total_amount !== null) {
                $bill->status = BillStatus::Review;
            }

            if ($approve || $bill->status === BillStatus::Approved) {
                if ($missing = $bill->missingForApproval()) {
                    throw ValidationException::withMessages(['bill' => implode(' ', $missing)]);
                }
            }

            if ($approve && $bill->status !== BillStatus::Approved) {
                $bill->forceFill([
                    'status' => BillStatus::Approved,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);
            }

            $bill->currency ??= $bill->lease?->currency;
            $bill->save();

            $this->syncBillCharge->handle($bill);
        });
    }
}
