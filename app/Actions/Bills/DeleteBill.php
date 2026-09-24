<?php

namespace App\Actions\Bills;

use App\Models\Bill;
use Illuminate\Support\Facades\DB;

/**
 * Removes the bill and the tenant's charge. The record and invoice file are kept (soft delete) for the history.
 */
class DeleteBill
{
    public function handle(Bill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $bill->ledgerEntry?->delete();
            $bill->delete();
        });
    }
}
