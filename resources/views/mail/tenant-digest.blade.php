@php
    $balance = $statement->balance();
    $overdue = $statement->overdueAmount();
    $next = $statement->nextDueCharge();
@endphp
<x-mail::message>
# Dzień dobry{{ $greetingNames ? ', '.$greetingNames : '' }}!

Poniżej zmiany w opłatach za mieszkanie **{{ $lease->apartment->label }}**.

@if ($digest->newCharges->isNotEmpty())
## Nowe opłaty

<x-mail::table>
| Za co | Kwota | Termin |
|:--|--:|--:|
@foreach ($digest->newCharges as $charge)
| {{ $charge->description }} | {{ $digest->money($charge->amount) }} | {{ $charge->due_on?->format('d.m.Y') ?? '—' }} |
@endforeach
</x-mail::table>
@endif

@if ($digest->changedCharges->isNotEmpty())
## Zmienione kwoty

<x-mail::table>
| Za co | Było | Jest |
|:--|--:|--:|
@foreach ($digest->changedCharges as $charge)
| {{ $charge->description }} | {{ $digest->money($charge->notified_amount) }} | **{{ $digest->money($charge->amount) }}** |
@endforeach
</x-mail::table>
@endif

@if ($digest->payments->isNotEmpty())
## Otrzymane wpłaty – dziękujemy!

@foreach ($digest->payments as $payment)
- {{ $payment->booked_on->format('d.m.Y') }}: {{ $digest->money($payment->amount) }}
@endforeach
@endif

<x-mail::panel>
@if ($balance > 0)
**Łącznie do zapłaty: {{ $digest->money($balance) }}**
@if ($overdue > 0)

w tym po terminie: {{ $digest->money($overdue) }}
@endif
@if ($next && $next->due_on)

Najbliższy termin: {{ $next->due_on->format('d.m.Y') }}
@endif
@elseif ($balance < 0)
**Masz nadpłatę: {{ $digest->money(-$balance) }}** – zaliczymy ją na kolejne opłaty.
@else
**Wszystko opłacone – dziękujemy!**
@endif
</x-mail::panel>

@if ($balance > 0 && $lease->bank_account)
**Dane do przelewu**<br>
Numer konta: {{ \App\Support\BankAccount::format($lease->bank_account) }}<br>
Tytuł: {{ $digest->transferTitle() }}
@endif

W razie pytań po prostu odpowiedz na tę wiadomość{{ $ownerNames ? ' – trafi do: '.$ownerNames : '' }}.

Pozdrawiamy,<br>
{{ $ownerNames ?: config('app.name') }}

<x-mail::subcopy>
Wiadomość wysłana automatycznie z serwisu {{ config('app.name') }} w imieniu właściciela mieszkania. Podsumowanie wysyłamy najwyżej raz dziennie, gdy pojawią się nowe opłaty.
</x-mail::subcopy>
</x-mail::message>
