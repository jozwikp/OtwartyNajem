<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Decides which lease an incoming transfer pays for. Every signal adds points and a reason:
 * a remembered payer account, the tenant's name (in any order, without Polish letters),
 * the apartment in the title, the amount due. Only clear winners are pre-selected –
 * everything doubtful is left for the owner to decide.
 */
class PaymentMatcher
{
    public const AUTO_SCORE = 70;

    public const REVIEW_SCORE = 30;

    protected const RENT_WORDS = '/\b(czynsz\w*|najem\w*|najmu|wynaj\w*|mieszkan\w*|oplat\w*|media|lokal\w*)\b/';

    /** @var Collection<int, Lease> */
    protected Collection $leases;

    public function __construct(User $user)
    {
        $recent = CarbonImmutable::today()->subMonths(4);

        $this->leases = Lease::query()
            ->whereIn('apartment_id', $user->apartments()->select('apartments.id'))
            ->with(['tenants', 'apartment', 'payers'])
            ->get()
            ->filter(fn (Lease $lease) => $lease->status() !== LeaseStatus::Ended || $lease->effectiveEndsOn()?->gte($recent));
    }

    /**
     * @return Collection<int, Lease>
     */
    public function leases(): Collection
    {
        return $this->leases;
    }

    /**
     * @param  array{booked_on: CarbonImmutable, amount: int, currency: string, description: string, sender_name: ?string, sender_account: ?string, title: ?string}  $row
     * @return array{status: BankTransactionStatus, lease_id: ?int, confidence: ?string, match_reason: string}
     */
    public function match(array $row): array
    {
        $text = static::normalize($row['description'].' '.$row['title'].' '.$row['sender_name']);
        $looksLikeRent = (bool) preg_match(self::RENT_WORDS, $text);

        $scores = $this->leases
            ->map(fn (Lease $lease) => ['lease' => $lease, ...$this->score($lease, $row, $text)])
            ->sortByDesc('score')
            ->values();

        $best = $scores->first();
        $second = $scores->get(1)['score'] ?? 0;

        if (! $best || $best['score'] < self::REVIEW_SCORE) {
            return $looksLikeRent
                ? $this->result(BankTransactionStatus::Review, null, 'low', __('Tytuł wygląda na opłatę za mieszkanie, ale nie rozpoznaliśmy najemcy.'))
                : $this->result(BankTransactionStatus::Ignored, null, null, __('Nie wygląda na wpłatę od najemcy.'));
        }

        /** @var Lease $lease */
        $lease = $best['lease'];
        $reason = implode(' · ', $best['reasons']);

        if ($row['currency'] !== $lease->currency) {
            return $this->result(BankTransactionStatus::Review, $lease->id, 'low', __('Przelew jest w :currency, a najem rozliczany w :lease.', ['currency' => $row['currency'], 'lease' => $lease->currency]));
        }

        if (str_contains($text, 'kaucj')) {
            return $this->result(BankTransactionStatus::Review, $lease->id, 'medium', $reason.' · '.__('Tytuł wskazuje na kaucję – kaucja nie jest wliczana do rozliczeń.'));
        }

        if ($this->hasSimilarManualPayment($lease, $row)) {
            return $this->result(BankTransactionStatus::Review, $lease->id, 'medium', $reason.' · '.__('Podobna wpłata jest już zapisana ręcznie – sprawdź, czy to nie ta sama.'));
        }

        if ($best['score'] >= self::AUTO_SCORE && $best['score'] - $second >= 25) {
            return $this->result(BankTransactionStatus::Suggested, $lease->id, 'high', $reason);
        }

        return $this->result(BankTransactionStatus::Review, $lease->id, $best['score'] >= self::AUTO_SCORE ? 'medium' : 'low', $reason);
    }

    /**
     * @param  array{booked_on: CarbonImmutable, amount: int, currency: string, description: string, sender_name: ?string, sender_account: ?string, title: ?string}  $row
     * @return array{score: int, reasons: list<string>}
     */
    protected function score(Lease $lease, array $row, string $text): array
    {
        $score = 0;
        $reasons = [];
        $words = array_flip(explode(' ', $text));

        if ($row['sender_account'] && $lease->payers->contains('account', $row['sender_account'])) {
            $score += 100;
            $reasons[] = __('Konto, z którego już wcześniej płacono za ten najem');
        }

        $nameScore = 0;
        $nameReason = null;
        foreach ($lease->tenants as $tenant) {
            [$points, $why] = $this->nameScore($tenant, $words);
            if ($points > $nameScore) {
                [$nameScore, $nameReason] = [$points, $why];
            }
        }
        if ($nameReason) {
            $score += $nameScore;
            $reasons[] = $nameReason;
        }

        $label = static::normalize($lease->apartment->label);
        $streetWords = array_values(array_filter(explode(' ', static::normalize($lease->apartment->street)), fn ($w) => mb_strlen($w) >= 4 && ! ctype_digit($w)));
        $building = preg_match('/\d+\s*[a-z]?/i', $lease->apartment->street, $m) ? static::normalize($m[0]) : null;

        if (mb_strlen($label) >= 5 && str_contains($text, $label)) {
            $score += 25;
            $reasons[] = __('Nazwa mieszkania w tytule');
        } elseif ($streetWords && isset($words[$streetWords[array_key_last($streetWords)]]) && $building && str_contains($text, $building)) {
            $score += 25;
            $reasons[] = __('Adres mieszkania w tytule');
        }

        if ($score > 0 && preg_match(self::RENT_WORDS, $text)) {
            $score += 10;
        }

        if ($score > 0 && $this->amountMatches($lease, $row['amount'])) {
            $score += 15;
            $reasons[] = __('Kwota zgadza się z należnością');
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * @param  array<string, int>  $words
     * @return array{0: int, 1: ?string}
     */
    protected function nameScore(LeaseTenant $tenant, array $words): array
    {
        $first = static::normalize($tenant->first_name);
        $last = static::normalize($tenant->last_name);
        $lastParts = array_filter(explode(' ', $last)); // double-barrelled surnames

        $hasLast = $lastParts !== [] && collect($lastParts)->every(fn ($part) => isset($words[$part]));
        $hasFirst = $first !== '' && isset($words[explode(' ', $first)[0]]);

        if ($hasLast && $hasFirst) {
            return [60, __('Imię i nazwisko najemcy (:name) w przelewie', ['name' => $tenant->full_name])];
        }

        if ($hasLast && mb_strlen($last) >= 4) {
            return [30, __('Nazwisko najemcy (:name) w przelewie', ['name' => $tenant->last_name])];
        }

        // Kowalski / Kowalska, typos: same beginning of the surname.
        $stem = mb_substr($last, 0, max(5, mb_strlen($last) - 2));
        if (mb_strlen($last) >= 6) {
            foreach (array_keys($words) as $word) {
                if (mb_strlen($word) >= 6 && str_starts_with((string) $word, $stem)) {
                    return [$hasFirst ? 45 : self::REVIEW_SCORE, __('Podobne nazwisko (:word) jak najemcy :name', ['word' => $word, 'name' => $tenant->full_name])];
                }
            }
        }

        return [0, null];
    }

    protected function amountMatches(Lease $lease, int $amount): bool
    {
        $statement = new LeaseStatement($lease);
        $open = $statement->charges->map(fn ($charge) => $statement->remainingFor($charge))->filter();

        return $amount === $statement->balance()
            || $open->contains(fn (int $value): bool => $value === $amount)
            || $open->sum() === $amount;
    }

    /**
     * @param  array{booked_on: CarbonImmutable, amount: int}  $row
     */
    protected function hasSimilarManualPayment(Lease $lease, array $row): bool
    {
        return $lease->ledgerEntries()
            ->where('kind', 'payment')
            ->whereNull('source_type')
            ->where('amount', $row['amount'])
            ->whereBetween('booked_on', [$row['booked_on']->subDays(5)->toDateString(), $row['booked_on']->addDays(5)->toDateString().' 23:59:59'])
            ->exists();
    }

    /**
     * @return array{status: BankTransactionStatus, lease_id: ?int, confidence: ?string, match_reason: string}
     */
    protected function result(BankTransactionStatus $status, ?int $leaseId, ?string $confidence, string $reason): array
    {
        return ['status' => $status, 'lease_id' => $leaseId, 'confidence' => $confidence, 'match_reason' => Str::limit($reason, 250)];
    }

    /**
     * Lowercase, no Polish letters, only letters/digits separated by single spaces.
     */
    public static function normalize(?string $value): string
    {
        $ascii = Str::ascii(mb_strtolower((string) $value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
