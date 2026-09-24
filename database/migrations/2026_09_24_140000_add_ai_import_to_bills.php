<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bills are now uploaded first and read by AI: until approved most fields may be empty,
 * the apartment is matched from the invoice address and the lease is found by the period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('apartment_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('approved')->after('lease_id')->index();
            $table->string('file_mime', 100)->nullable()->after('file_name');
            $table->json('ai_result')->nullable();
            $table->text('ai_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->change();
            $table->string('category', 20)->nullable()->change();
            $table->date('issued_on')->nullable()->change();
            $table->date('period_from')->nullable()->change();
            $table->date('period_to')->nullable()->change();
            $table->char('currency', 3)->nullable()->change();
            $table->unsignedBigInteger('total_amount')->nullable()->change();
            $table->unsignedBigInteger('tenant_amount')->nullable()->change();
            $table->date('due_on')->nullable()->change();
        });

        DB::table('bills')->whereNull('apartment_id')->update([
            'apartment_id' => DB::raw('(select apartment_id from leases where leases.id = bills.lease_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('apartment_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'file_mime', 'ai_result', 'ai_error', 'processed_at', 'approved_at']);
        });
    }
};
