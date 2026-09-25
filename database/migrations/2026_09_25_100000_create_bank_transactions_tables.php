<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank statement import. Only incoming transfers are stored – the rest of the
 * statement (the owner's own spending) is never saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('bank', 50)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('incoming_total')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->date('booked_on');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->text('description');
            $table->string('sender_name')->nullable();
            $table->string('sender_account', 40)->nullable();
            $table->string('title')->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidence', 10)->nullable();
            $table->string('match_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });

        // Accounts a lease's rent was paid from before – recognised automatically next time.
        Schema::create('lease_payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('account', 40);
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'account']);
            $table->index('account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_payers');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_imports');
    }
};
