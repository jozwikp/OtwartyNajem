<?php

namespace App\Actions\Bills;

use App\Models\Bill;
use App\Models\User;

class ApproveBill
{
    public function __construct(protected UpdateBill $updateBill) {}

    public function handle(Bill $bill, User $user): void
    {
        $this->updateBill->handle($bill, $user, [], approve: true);
    }
}
