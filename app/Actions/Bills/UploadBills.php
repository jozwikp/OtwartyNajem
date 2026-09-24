<?php

namespace App\Actions\Bills;

use App\Enums\BillStatus;
use App\Jobs\ReadBill;
use App\Models\Bill;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UploadBills
{
    /**
     * Stores the files and sends each one to the AI in the background.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<Bill>
     */
    public function handle(User $user, array $files): array
    {
        $bills = [];

        foreach ($files as $file) {
            $bill = Bill::create([
                'status' => BillStatus::Queued,
                'file_path' => $file->store("bills/{$user->id}", 'local'),
                'file_name' => $file->getClientOriginalName(),
                'file_mime' => $file->getMimeType(),
                'created_by' => $user->id,
            ]);

            ReadBill::dispatch($bill);
            $bills[] = $bill;
        }

        return $bills;
    }
}
