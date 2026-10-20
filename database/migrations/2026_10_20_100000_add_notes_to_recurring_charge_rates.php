<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each amount of a fixed charge can say where it comes from (e.g. the housing
 * association's resolution behind a new administration fee). Encrypted like other notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_charge_rates', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_charge_rates', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
