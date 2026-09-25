<?php

namespace App\Actions\Payments;

use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\BankStatementParser;
use App\Support\PaymentMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Reads a bank statement, keeps only incoming transfers and matches them to tenants.
 * Transfers already imported before (overlapping statements) are skipped.
 */
class ImportBankStatement
{
    public function __construct(protected BankStatementParser $parser) {}

    public function handle(User $user, string $contents, string $fileName): BankImport
    {
        $statement = $this->parser->parse($contents);
        $incoming = array_values(array_filter($statement['rows'], fn ($row) => $row['amount'] > 0));
        $matcher = new PaymentMatcher($user);

        return DB::transaction(function () use ($user, $fileName, $statement, $incoming, $matcher) {
            $import = BankImport::create([
                'user_id' => $user->id,
                'file_name' => $fileName,
                'bank' => $statement['bank'],
                'period_from' => $statement['period_from'],
                'period_to' => $statement['period_to'],
                'rows_total' => count($statement['rows']),
                'incoming_total' => count($incoming),
            ]);

            $seen = [];
            foreach ($incoming as $row) {
                $base = implode('|', [$row['booked_on']->toDateString(), $row['amount'], $row['currency'], PaymentMatcher::normalize($row['description'])]);
                $occurrence = $seen[$base] = ($seen[$base] ?? 0) + 1; // two identical transfers on one day
                $fingerprint = hash('sha256', $base.'#'.$occurrence);

                if (BankTransaction::where('user_id', $user->id)->where('fingerprint', $fingerprint)->exists()) {
                    $import->increment('duplicates');

                    continue;
                }

                $import->transactions()->create([
                    'user_id' => $user->id,
                    'fingerprint' => $fingerprint,
                    'booked_on' => $row['booked_on'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'description' => $row['description'],
                    'sender_name' => $row['sender_name'],
                    'sender_account' => $row['sender_account'],
                    'title' => $row['title'],
                    ...$matcher->match($row),
                ]);
            }

            return $import;
        });
    }
}
