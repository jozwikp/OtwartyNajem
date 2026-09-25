<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Personal data is encrypted at rest with the application key (APP_KEY): tenants' names,
 * e-mails and phones, bank accounts, bank statement details, free-text notes, what the AI
 * read from invoices and the change history. A stolen database or backup reveals none of it.
 *
 * Encrypted values are long and not searchable, so the columns become TEXT. Bank accounts that
 * must be looked up get a keyed hash next to them ("blind index").
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>> table => columns encrypted as plain strings
     */
    protected array $strings = [
        'lease_tenants' => ['first_name', 'last_name', 'email', 'phone'],
        'leases' => ['bank_account', 'notes', 'deposit_return_notes'],
        'bank_transactions' => ['description', 'sender_name', 'sender_account', 'title'],
        'lease_payers' => ['account', 'name'],
        'ledger_entries' => ['notes'],
    ];

    /**
     * @var array<string, list<string>> table => JSON columns encrypted as a whole
     */
    protected array $json = [
        'bills' => ['ai_result'],
        'activity_log' => ['properties', 'attribute_changes'],
    ];

    public function up(): void
    {
        Schema::table('lease_payers', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'account']);
            $table->dropIndex(['account']);
            $table->string('account_hash', 64)->nullable()->after('account');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('apartment_id')->nullable()->after('causer_id')->index();
        });

        foreach ([...$this->strings, ...$this->json] as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $nullable = ! ($table === 'lease_tenants' && in_array($column, ['first_name', 'last_name'], true))
                        && ! ($table === 'bank_transactions' && $column === 'description')
                        && ! ($table === 'lease_payers' && $column === 'account');

                    $nullable ? $blueprint->text($column)->nullable()->change() : $blueprint->text($column)->change();
                }
            });
        }

        // Activity entries are found by apartment – keep that id outside the encrypted properties.
        DB::table('activity_log')->whereNotNull('properties')->orderBy('id')->each(function ($row) {
            $apartmentId = json_decode((string) $row->properties, true)['apartment_id'] ?? null;

            if (is_int($apartmentId)) {
                DB::table('activity_log')->where('id', $row->id)->update(['apartment_id' => $apartmentId]);
            }
        });

        DB::table('lease_payers')->orderBy('id')->each(function ($row) {
            DB::table('lease_payers')->where('id', $row->id)->update([
                'account_hash' => hash_hmac('sha256', (string) $row->account, (string) config('app.key')),
            ]);
        });

        foreach ([...$this->strings, ...$this->json] as $table => $columns) {
            $this->encryptColumns($table, $columns);
        }

        Schema::table('lease_payers', function (Blueprint $table) {
            $table->unique(['lease_id', 'account_hash']);
            $table->index('account_hash');
        });

        // Invoice files already on disk get encrypted too.
        DB::table('bills')->orderBy('id')->each(function ($bill) {
            $disk = Storage::disk('local');

            if (str_ends_with((string) $bill->file_path, '.enc') || ! $disk->exists($bill->file_path)) {
                return;
            }

            $encryptedPath = $bill->file_path.'.enc';
            $disk->put($encryptedPath, Crypt::encryptString((string) $disk->get($bill->file_path)));
            DB::table('bills')->where('id', $bill->id)->update(['file_path' => $encryptedPath]);
            $disk->delete($bill->file_path);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    protected function encryptColumns(string $table, array $columns): void
    {
        DB::table($table)->orderBy('id')->each(function ($row) use ($table, $columns) {
            $changes = [];

            foreach ($columns as $column) {
                $value = $row->{$column};

                if ($value !== null && ! $this->isEncrypted((string) $value)) {
                    $changes[$column] = Crypt::encryptString((string) $value);
                }
            }

            if ($changes) {
                DB::table($table)->where('id', $row->id)->update($changes);
            }
        });
    }

    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        foreach ([...$this->strings, ...$this->json] as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function ($row) use ($table, $columns) {
                $changes = [];
                foreach ($columns as $column) {
                    if ($row->{$column} !== null && $this->isEncrypted((string) $row->{$column})) {
                        $changes[$column] = Crypt::decryptString((string) $row->{$column});
                    }
                }
                if ($changes) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            });
        }

        Schema::table('lease_payers', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'account_hash']);
            $table->dropIndex(['account_hash']);
            $table->dropColumn('account_hash');
            $table->unique(['lease_id', 'account']);
            $table->index('account');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['apartment_id']);
            $table->dropColumn('apartment_id');
        });
    }
};
