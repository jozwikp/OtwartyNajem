<?php

namespace App\Actions\Leases;

use App\Models\Bill;
use Illuminate\Support\Facades\DB;

/**
 * Removes the bill and its charge. The record and invoice file are kept (soft delete) for the history.
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
