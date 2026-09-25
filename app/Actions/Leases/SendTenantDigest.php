<?php

namespace App\Actions\Leases;

use App\Mail\TenantDigestMail;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Support\Locales;
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

        // Each tenant reads it in their own language; owners always get a copy of what was sent.
        $byLanguage = $lease->tenants
            ->filter(fn (LeaseTenant $tenant) => filled($tenant->email))
            ->groupBy(fn (LeaseTenant $tenant) => in_array($tenant->locale, Locales::codes(), true) ? $tenant->locale : 'pl');

        foreach ($byLanguage as $locale => $tenants) {
            Mail::to($tenants->pluck('email')->unique()->values()->all())
                ->cc($ownerEmails)
                ->locale((string) $locale)
                ->send(new TenantDigestMail($digest, $tenants->values()));
        }

        DB::transaction(function () use ($lease, $digest, $ownerEmails, $byLanguage) {
            $digest->markAsSent();

            activity('leases')
                ->performedOn($lease)
                ->event('tenant_notified')
                ->withProperties([
                    'recipients' => $lease->tenantEmails(),
                    'languages' => $byLanguage->keys()->all(),
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
