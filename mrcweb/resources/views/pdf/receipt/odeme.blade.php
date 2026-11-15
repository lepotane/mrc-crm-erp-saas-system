<div class="pay" data-blok="odeme" data-idx="{{ $idx ?? 0 }}">
  <div>{{ __('Ödeme') }}: {{ $paymentMethodLabel }} · {{ $money($paid) }} {{ $document->currency_code }}</div>
  @if($tendered !== null)
  <div>{{ __('Alınan') }}: {{ $money($tendered) }} · {{ __('Para üstü') }}: {{ $money($change) }}</div>
  @endif
</div>
