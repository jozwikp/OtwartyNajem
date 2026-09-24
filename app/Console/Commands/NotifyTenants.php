<?php

namespace App\Console\Commands;

use App\Actions\Leases\SendTenantDigest;
use App\Models\Lease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tenants:notify')]
#[Description('E-mail each tenant one daily summary of new charges, changed amounts and payments')]
class NotifyTenants extends Command
{
    public function handle(SendTenantDigest $sendTenantDigest): int
    {
        $sent = 0;

        Lease::query()
            ->where('notify_tenants', true)
            ->whereHas('apartment')
            ->whereHas('tenants', fn ($q) => $q->whereNotNull('email'))
            ->lazyById()
            ->each(function (Lease $lease) use ($sendTenantDigest, &$sent) {
                try {
                    $sent += (int) $sendTenantDigest->handle($lease);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Lease {$lease->id}: {$e->getMessage()}");
                }
            });

        $this->info("Sent {$sent} summaries.");

        return self::SUCCESS;
    }
}
