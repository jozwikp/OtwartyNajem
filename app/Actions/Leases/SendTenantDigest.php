<?php

namespace App\Actions\Leases;

use App\Mail\TenantDigestMail;
use App\Models\Lease;
use App\Support\TenantDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the lease's daily summary to the tenants (if there is anything new) and records it in the history.
 */
class SendTenantDigest
{
    public function handle(Lease $lease): bool
    {
        $lease->load(['tenants', 'apartment.owners']);

        if (! $lease->notify_tenants || ! $lease->apartment || $lease->tenantEmails() === []) {
            return false;
        }

        $digest = new TenantDigest($lease);

        if (! $digest->hasNews()) {
            return false;
        }

        Mail::to($lease->tenantEmails())->send(new TenantDigestMail($digest));

        DB::transaction(function () use ($lease, $digest) {
            $digest->markAsSent();

            activity('leases')
                ->performedOn($lease)
                ->event('tenant_notified')
                ->withProperties([
                    'recipients' => $lease->tenantEmails(),
                    'new_charges' => $digest->newCharges->map(fn ($e) => ['description' => $e->description, 'amount' => $e->amount])->all(),
                    'changed_charges' => $digest->changedCharges->map(fn ($e) => ['description' => $e->description, 'from' => $e->notified_amount, 'to' => $e->amount])->all(),
                    'payments' => $digest->payments->sum('amount'),
                    'balance' => $digest->statement->balance(),
                    'subject' => $digest->subject(),
                ])
                ->log('tenant_notified');
        });

        return true;
    }
}
