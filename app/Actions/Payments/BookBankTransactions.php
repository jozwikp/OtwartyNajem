<?php

namespace App\Actions\Payments;

use App\Actions\Leases\RecordPayment;
use App\Enums\BankTransactionStatus;
use App\Enums\PaymentMethod;
use App\Models\BankTransaction;
use App\Models\Lease;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books chosen incoming transfers as tenant payments and remembers the payer's account,
 * so the next statement recognises it automatically.
 */
class BookBankTransactions
{
    public function __construct(protected RecordPayment $recordPayment) {}

    /**
     * @param  array<int, int>  $choices  bank transaction id => lease id
     */
    public function handle(User $user, array $choices): int
    {
        return DB::transaction(function () use ($user, $choices) {
            $booked = 0;

            foreach ($choices as $transactionId => $leaseId) {
                $transaction = BankTransaction::where('user_id', $user->id)->lockForUpdate()->findOrFail($transactionId);

                if (! $transaction->status->isPending()) {
                    continue;
                }

                $lease = Lease::with('apartment')->findOrFail($leaseId);

                if (! $user->can('update', $lease->apartment)) {
                    throw ValidationException::withMessages(['booking' => __('Nie masz dostępu do tego najmu.')]);
                }

                if ($lease->currency !== $transaction->currency) {
                    throw ValidationException::withMessages(['booking' => __('Przelew z :date jest w :currency, a najem rozliczany w :lease.', [
                        'date' => $transaction->booked_on->format('d.m.Y'), 'currency' => $transaction->currency, 'lease' => $lease->currency,
                    ])]);
                }

                $this->recordPayment->handle(
                    $lease,
                    $user,
                    $transaction->amount,
                    $transaction->booked_on,
                    PaymentMethod::Transfer,
                    trim(($transaction->sender_name ?? '').($transaction->title ? ' – '.$transaction->title : '')) ?: null,
                    $transaction,
                );

                $transaction->forceFill([
                    'status' => BankTransactionStatus::Booked,
                    'lease_id' => $lease->id,
                    'decided_by' => $user->id,
                    'decided_at' => now(),
                ])->save();

                if ($transaction->sender_account && ! $lease->payers()->forAccount($transaction->sender_account)->exists()) {
                    $lease->payers()->create(['account' => $transaction->sender_account, 'name' => $transaction->sender_name]);
                }

                $booked++;
            }

            return $booked;
        });
    }
}
