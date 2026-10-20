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

@if ($digest->newCharges->count() > 1)
<x-mail::panel>
**{{ __('Razem nowe opłaty: :amount', ['amount' => $digest->money($digest->newChargesTotal())]) }}**
</x-mail::panel>

@endif
@if ($lease->bank_account)
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
