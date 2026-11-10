<?php

use App\Support\Money;
use App\Support\PortfolioStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Raporty')] class extends Component
{
    #[Url(as: 'rok')]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= CarbonImmutable::today()->year;
    }

    #[Computed]
    public function statement(): PortfolioStatement
    {
        return new PortfolioStatement(Auth::user());
    }

    #[Computed]
    public function years(): array
    {
        return $this->statement->years();
    }

    #[Computed]
    public function currencies(): array
    {
        return $this->statement->currencies();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{month: CarbonImmutable, accrual_income: int, accrual_expense: int, accrual_balance: int, cash_income: int, cash_expense: int, cash_balance: int}>
     */
    public function monthsOf(string $currency)
    {
        return $this->statement->monthsOf($currency, $this->year);
    }

    /**
     * @return array{accrual_income: int, accrual_expense: int, accrual_balance: int, cash_income: int, cash_expense: int, cash_balance: int}
     */
    public function totalsOf(string $currency): array
    {
        return $this->statement->totalsOf($currency, $this->year);
    }

    public function monthLabel(CarbonImmutable $month): string
    {
        return Str::ucfirst($month->translatedFormat('M'));
    }

    /**
     * An SVG path for a bar with square bottom corners and rounded top corners, anchored to $y+$height.
     */
    public function barPath(float $x, float $y, float $width, float $height, float $radius = 3): string
    {
        $r = max(0, min($radius, $height, $width / 2));

        return sprintf(
            'M%1$.2F,%2$.2F Q%1$.2F,%3$.2F %4$.2F,%3$.2F L%5$.2F,%3$.2F Q%6$.2F,%3$.2F %6$.2F,%2$.2F L%6$.2F,%7$.2F L%1$.2F,%7$.2F Z',
            $x, $y + $r, $y, $x + $r, $x + $width - $r, $x + $width, $y + $height,
        );
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Raporty') }}</flux:heading>
            <flux:text class="mt-1 text-base">{{ __('Przychody i rozchody ze wszystkich mieszkań, miesiąc po miesiącu — memoriałowo (co naliczono) i kasowo (co faktycznie wpłynęło i wypłynęło).') }}</flux:text>
        </div>

        <flux:select wire:model.live="year" class="max-w-28" size="sm">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->currencies === [])
        <div class="flex flex-col items-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-16 text-center dark:border-stone-700">
            <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-badge">
                <flux:icon.chart-bar class="size-8 text-accent-content" />
            </div>
            <flux:heading size="lg">{{ __('Brak jeszcze danych do zestawienia') }}</flux:heading>
            <flux:text class="mt-2 max-w-md text-base">
                {{ __('Zestawienie pojawi się, gdy jakiś najem zacznie naliczać czynsz albo zatwierdzisz pierwszy rachunek.') }}
            </flux:text>
        </div>
    @else
        @foreach ($this->currencies as $currency)
            @php
                $months = $this->monthsOf($currency);
                $totals = $this->totalsOf($currency);
                $maxValue = max(1, (int) $months->max('accrual_income'), (int) $months->max('accrual_expense'));

                $chartWidth = 760;
                $chartHeight = 220;
                $baseline = $chartHeight - 24;
                $plotHeight = $baseline - 10;
                $groupWidth = $chartWidth / 12;
                $barWidth = ($groupWidth - 16) / 2;
            @endphp

            <section class="mt-2 mb-10 last:mb-0">
                @if (count($this->currencies) > 1)
                    <flux:heading size="lg" class="mb-4">{{ $currency }}</flux:heading>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <flux:text class="text-xs font-medium tracking-wide text-stone-500 uppercase dark:text-stone-400">{{ __('Memoriałowo — co naliczono') }}</flux:text>
                        <div class="mt-2 grid gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text>{{ __('Przychody') }}</flux:text>
                                <div class="mt-1 text-xl font-semibold" style="color: #16a34a">{{ Money::format($totals['accrual_income'], $currency) }}</div>
                            </div>
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text>{{ __('Rozchody') }}</flux:text>
                                <div class="mt-1 text-xl font-semibold" style="color: #ea580c">{{ Money::format($totals['accrual_expense'], $currency) }}</div>
                            </div>
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text>{{ __('Saldo') }}</flux:text>
                                <div class="mt-1 text-xl font-semibold">{{ Money::format($totals['accrual_balance'], $currency) }}</div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <flux:text class="text-xs font-medium tracking-wide text-stone-500 uppercase dark:text-stone-400">{{ __('Kasowo — co faktycznie się przelało') }}</flux:text>
                        <div class="mt-2 grid gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text>{{ __('Przychody') }}</flux:text>
                                <div class="mt-1 text-xl font-semibold" style="color: #16a34a">{{ Money::format($totals['cash_income'], $currency) }}</div>
                            </div>
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text class="flex items-center gap-1">
                                    {{ __('Rozchody') }}
                                    <flux:tooltip :content="__('Przybliżone: kwota rachunku w miesiącu jego zatwierdzenia, bo system nie zna daty zapłaty dostawcy.')">
                                        <flux:icon.information-circle variant="micro" />
                                    </flux:tooltip>
                                </flux:text>
                                <div class="mt-1 text-xl font-semibold" style="color: #ea580c">{{ Money::format($totals['cash_expense'], $currency) }}</div>
                            </div>
                            <div class="rounded-2xl border border-line bg-card p-5">
                                <flux:text>{{ __('Saldo') }}</flux:text>
                                <div class="mt-1 text-xl font-semibold">{{ Money::format($totals['cash_balance'], $currency) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <flux:text class="mt-6 text-sm font-medium">{{ __('Wykres (memoriałowo)') }}</flux:text>
                <div class="mt-2 flex items-center gap-4 text-sm">
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full" style="background-color: #16a34a"></span>
                        {{ __('Przychody') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full" style="background-color: #ea580c"></span>
                        {{ __('Rozchody') }}
                    </span>
                </div>

                <div class="mt-2 overflow-x-auto rounded-2xl border border-line bg-card p-5">
                    <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full" style="min-width: 640px" role="img" aria-label="{{ __('Wykres przychodów i rozchodów (memoriałowo) w :year roku', ['year' => $year]) }}">
                        @foreach ([0, 0.25, 0.5, 0.75, 1] as $fraction)
                            <line x1="0" y1="{{ $baseline - $plotHeight * $fraction }}" x2="{{ $chartWidth }}" y2="{{ $baseline - $plotHeight * $fraction }}" stroke="var(--color-line)" stroke-width="1" />
                        @endforeach

                        @foreach ($months as $i => $row)
                            @php
                                $groupX = $i * $groupWidth + 8;
                                $incomeHeight = $maxValue > 0 ? ($row['accrual_income'] / $maxValue) * $plotHeight : 0;
                                $expenseHeight = $maxValue > 0 ? ($row['accrual_expense'] / $maxValue) * $plotHeight : 0;
                            @endphp

                            @if ($row['accrual_income'] > 0)
                                <path d="{{ $this->barPath($groupX, $baseline - $incomeHeight, $barWidth, $incomeHeight) }}" fill="#16a34a">
                                    <title>{{ __('Przychody') }} — {{ $this->monthLabel($row['month']) }}: {{ Money::format($row['accrual_income'], $currency) }}</title>
                                </path>
                            @endif

                            @if ($row['accrual_expense'] > 0)
                                <path d="{{ $this->barPath($groupX + $barWidth + 2, $baseline - $expenseHeight, $barWidth, $expenseHeight) }}" fill="#ea580c">
                                    <title>{{ __('Rozchody') }} — {{ $this->monthLabel($row['month']) }}: {{ Money::format($row['accrual_expense'], $currency) }}</title>
                                </path>
                            @endif

                            <text x="{{ $groupX + $barWidth + 1 }}" y="{{ $chartHeight - 6 }}" text-anchor="middle" font-size="11" fill="var(--color-ink)" opacity="0.6">{{ $this->monthLabel($row['month']) }}</text>
                        @endforeach

                        <line x1="0" y1="{{ $baseline }}" x2="{{ $chartWidth }}" y2="{{ $baseline }}" stroke="var(--color-ink)" stroke-width="1" opacity="0.3" />
                    </svg>
                </div>

                <div class="mt-4 overflow-x-auto rounded-2xl border border-line">
                    <table class="w-full text-sm">
                        <thead class="bg-tray text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium" rowspan="2">{{ __('Miesiąc') }}</th>
                                <th class="border-l border-line px-4 py-1 text-center font-medium" colspan="3">{{ __('Memoriałowo') }}</th>
                                <th class="border-l border-line px-4 py-1 text-center font-medium" colspan="3">{{ __('Kasowo') }}</th>
                            </tr>
                            <tr class="text-xs text-stone-500 dark:text-stone-400">
                                <th class="border-l border-line px-4 py-1 text-right font-medium">{{ __('Przychody') }}</th>
                                <th class="px-4 py-1 text-right font-medium">{{ __('Rozchody') }}</th>
                                <th class="px-4 py-1 text-right font-medium">{{ __('Saldo') }}</th>
                                <th class="border-l border-line px-4 py-1 text-right font-medium">{{ __('Przychody') }}</th>
                                <th class="px-4 py-1 text-right font-medium">{{ __('Rozchody*') }}</th>
                                <th class="px-4 py-1 text-right font-medium">{{ __('Saldo') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($months->reverse() as $row)
                                <tr wire:key="month-{{ $currency }}-{{ $row['month']->format('Y-m') }}">
                                    <td class="px-4 py-2">{{ Str::ucfirst($row['month']->translatedFormat('F Y')) }}</td>
                                    <td class="border-l border-line px-4 py-2 text-right">{{ Money::format($row['accrual_income'], $currency) }}</td>
                                    <td class="px-4 py-2 text-right">{{ Money::format($row['accrual_expense'], $currency) }}</td>
                                    <td class="px-4 py-2 text-right font-medium">{{ Money::format($row['accrual_balance'], $currency) }}</td>
                                    <td class="border-l border-line px-4 py-2 text-right">{{ Money::format($row['cash_income'], $currency) }}</td>
                                    <td class="px-4 py-2 text-right">{{ Money::format($row['cash_expense'], $currency) }}</td>
                                    <td class="px-4 py-2 text-right font-medium">{{ Money::format($row['cash_balance'], $currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-line bg-tray font-medium">
                            <tr>
                                <td class="px-4 py-2">{{ __('Razem') }}</td>
                                <td class="border-l border-line px-4 py-2 text-right">{{ Money::format($totals['accrual_income'], $currency) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::format($totals['accrual_expense'], $currency) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::format($totals['accrual_balance'], $currency) }}</td>
                                <td class="border-l border-line px-4 py-2 text-right">{{ Money::format($totals['cash_income'], $currency) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::format($totals['cash_expense'], $currency) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::format($totals['cash_balance'], $currency) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <flux:text class="mt-2 text-xs">
                    {{ __('* Rozchód kasowy jest przybliżony: to kwota rachunku przypisana do miesiąca, w którym go zatwierdziłeś — system nie zna dokładnej daty zapłaty dostawcy.') }}
                </flux:text>
            </section>
        @endforeach
    @endif
</div>
