<?php

namespace App\Actions\Leases;

use App\Enums\LedgerKind;
use App\Models\Bill;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores the invoice file and charges the tenant with the bill amount.
 */
class CreateBill
{
    /**
     * @param  array<string, mixed>  $data  bill attributes (amounts in minor units)
     */
    public function handle(Lease $lease, User $user, array $data, UploadedFile $file): Bill
    {
        $path = $file->store("bills/{$lease->id}", 'local');

        try {
            return DB::transaction(function () use ($lease, $user, $data, $file, $path) {
                $bill = new Bill([
                    ...$data,
                    'currency' => $lease->currency,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'created_by' => $user->id,
                ]);
                $lease->bills()->save($bill);

                if ($bill->tenant_amount > 0) {
                    $entry = new LedgerEntry([
                        'kind' => LedgerKind::Charge,
                        'currency' => $lease->currency,
                        'amount' => $bill->tenant_amount,
                        'booked_on' => $bill->issued_on,
                        'due_on' => $bill->due_on,
                        'description' => static::describe($bill),
                        'created_by' => $user->id,
                    ]);
                    $entry->lease()->associate($lease);
                    $entry->source()->associate($bill);
                    $entry->save();
                }

                return $bill;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    public static function describe(Bill $bill): string
    {
        return __('Rachunek: :category', ['category' => $bill->category->label()])
            .($bill->supplier ? ' ('.$bill->supplier.')' : '')
            .' – '.$bill->period_from->format('d.m').'–'.$bill->period_to->format('d.m.Y');
    }
}
