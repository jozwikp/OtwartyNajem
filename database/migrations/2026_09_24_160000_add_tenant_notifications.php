<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants get one e-mail a day (after 16:00) with what changed in their settlement.
 * A ledger entry remembers when and with which amount it was last sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('notify_tenants')->default(true)->after('notes');
            $table->timestamp('last_notified_at')->nullable()->after('notify_tenants');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
            $table->unsignedBigInteger('notified_amount')->nullable();
            $table->index(['lease_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['lease_id', 'notified_at']);
            $table->dropColumn(['notified_at', 'notified_amount']);
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['notify_tenants', 'last_notified_at']);
        });
    }
};
