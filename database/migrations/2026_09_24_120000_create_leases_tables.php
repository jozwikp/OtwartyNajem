<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All money amounts are stored as integers in minor units (grosze, cents)
 * in the currency of the lease.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apartment_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->char('currency', 3)->default('PLN');
            $table->unsignedTinyInteger('payment_due_day')->default(10);
            $table->string('bank_account', 40)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('deposit_amount')->nullable();
            $table->string('deposit_method', 20)->nullable();
            $table->date('deposit_returned_on')->nullable();
            $table->unsignedBigInteger('deposit_returned_amount')->nullable();
            $table->text('deposit_return_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['apartment_id', 'starts_on']);
        });

        Schema::create('lease_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('recurring_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_charge_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_charge_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('valid_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_charge_id', 'valid_from']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('issued_on');
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('tenant_amount');
            $table->date('due_on');
            $table->string('file_path');
            $table->string('file_name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->date('booked_on');
            $table->date('due_on')->nullable();
            $table->date('period')->nullable();
            $table->string('description');
            $table->nullableMorphs('source');
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'period']);
            $table->index(['lease_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('recurring_charge_rates');
        Schema::dropIfExists('recurring_charges');
        Schema::dropIfExists('lease_tenants');
        Schema::dropIfExists('leases');
    }
};
