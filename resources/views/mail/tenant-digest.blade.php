@php
    $balance = $statement->balance();
    $overdue = $statement->overdueAmount();
    $next = $statement->nextDueCharge();
@endphp
<x-mail::message>
# {{ $greetingNames ? __('Dzień dobry, :name!', ['name' => $greetingNames]) : __('Dzień dobry!') }}

{{ __('Poniżej zmiany w opłatach za mieszkanie') }} **{{ $lease->apartment->label }}**.

@if ($digest->newCharges->isNotEmpty())
## {{ __('Nowe opłaty') }}

<x-mail::table>
| {{ __('Za co') }} | {{ __('Kwota') }} | {{ __('Termin') }} |
|:--|--:|--:|
@foreach ($digest->newCharges as $charge)
| {{ $charge->displayDescription() }} | {{ $digest->money($charge->amount) }} | {{ $charge->due_on?->format('d.m.Y') ?? '—' }} |
@endforeach
</x-mail::table>
@endif

@if ($digest->changedCharges->isNotEmpty())
## {{ __('Zmienione kwoty') }}

<x-mail::table>
| {{ __('Za co') }} | {{ __('Było') }} | {{ __('Jest') }} |
|:--|--:|--:|
@foreach ($digest->changedCharges as $charge)
| {{ $charge->displayDescription() }} | {{ $digest->money($charge->notified_amount) }} | **{{ $digest->money($charge->amount) }}** |
@endforeach
</x-mail::table>
@endif

@if ($digest->payments->isNotEmpty())
## {{ __('Otrzymane wpłaty – dziękujemy!') }}

@foreach ($digest->payments as $payment)
- {{ $payment->booked_on->format('d.m.Y') }}: {{ $digest->money($payment->amount) }}
@endforeach
@endif

<x-mail::panel>
@if ($balance > 0)
**{{ __('Łącznie do zapłaty: :amount', ['amount' => $digest->money($balance)]) }}**
@if ($overdue > 0)

{{ __('w tym po terminie: :amount', ['amount' => $digest->money($overdue)]) }}
@endif
@if ($next && $next->due_on)

{{ __('Najbliższy termin: :date', ['date' => $next->due_on->format('d.m.Y')]) }}
@endif
@elseif ($balance < 0)
**{{ __('Masz nadpłatę: :amount', ['amount' => $digest->money(-$balance)]) }}** – {{ __('zaliczymy ją na kolejne opłaty.') }}
@else
**{{ __('Wszystko opłacone – dziękujemy!') }}**
@endif
</x-mail::panel>

@if ($balance > 0 && $lease->bank_account)
**{{ __('Dane do przelewu') }}**<br>
{{ __('Numer konta') }}: {{ \App\Support\BankAccount::format($lease->bank_account) }}<br>
{{ __('Tytuł') }}: {{ $digest->transferTitle() }}
@endif

{{ $ownerNames ? __('W razie pytań po prostu odpowiedz na tę wiadomość – trafi do: :names.', ['names' => $ownerNames]) : __('W razie pytań po prostu odpowiedz na tę wiadomość.') }}

{{ __('Pozdrawiamy') }},<br>
{{ $ownerNames ?: config('app.name') }}

<x-mail::subcopy>
{{ __('Wiadomość wysłana automatycznie z serwisu :app w imieniu właściciela mieszkania. Podsumowanie wysyłamy najwyżej raz dziennie, gdy pojawią się nowe opłaty.', ['app' => config('app.name')]) }}
</x-mail::subcopy>
</x-mail::message>
