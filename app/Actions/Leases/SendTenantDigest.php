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

        if (! $lease->notify_tenants || $lease->tenantEmails() === []) {
            return false;
        }

        $digest = new TenantDigest($lease);

        if (! $digest->hasNews()) {
            return false;
        }

        $ownerEmails = $lease->apartment->owners->pluck('email')->filter()->unique()->values()->all();

        // Owners always get a copy of what their tenant was told.
        Mail::to($lease->tenantEmails())->cc($ownerEmails)->locale('pl')->send(new TenantDigestMail($digest));

        DB::transaction(function () use ($lease, $digest, $ownerEmails) {
            $digest->markAsSent();

            activity('leases')
                ->performedOn($lease)
                ->event('tenant_notified')
                ->withProperties([
                    'recipients' => $lease->tenantEmails(),
                    'cc' => $ownerEmails,
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
