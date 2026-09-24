<?php

namespace App\Jobs;

use App\Actions\Bills\ApplyBillReading;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends an uploaded invoice to the AI and fills the bill with the answer.
 */
class ReadBill implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public Bill $bill)
    {
        $this->afterCommit();
    }

    public function handle(BillReader $reader, ApplyBillReading $apply): void
    {
        $bill = $this->bill;

        if ($bill->status === BillStatus::Approved || $bill->trashed()) {
            return;
        }

        $bill->forceFill(['status' => BillStatus::Processing])->saveQuietly();

        $apartments = User::find($bill->created_by)?->apartments()->get() ?? collect();

        try {
            $result = $reader->read($bill->file_path, (string) $bill->file_mime, $bill->file_name, $apartments);
        } catch (Throwable $e) {
            report($e);
            $this->failed($e);

            return;
        }

        $apply->handle($bill, $result, $apartments->pluck('id'));

        // One readable history entry instead of a list of changed fields.
        $bill->disableLogging()->save();
        $bill->enableLogging();

        activity('bills')
            ->performedOn($bill)
            ->event('ai_read')
            ->withProperties([
                'confidence' => $result['match_confidence'] ?? null,
                'address_on_invoice' => $result['address_on_invoice'] ?? null,
                'amount' => $bill->total_amount,
                'category' => $bill->category?->value,
                'file_name' => $bill->file_name,
            ])
            ->log('ai_read');
    }

    public function failed(?Throwable $exception): void
    {
        $this->bill->forceFill([
            'status' => BillStatus::Failed,
            'ai_error' => $exception?->getMessage() ?: __('Nie udało się odczytać dokumentu.'),
            'processed_at' => now(),
        ])->saveQuietly();
    }
}
