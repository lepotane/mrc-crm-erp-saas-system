<table class="totals" data-blok="toplam" data-idx="{{ $idx ?? 0 }}">
  @if($toplam['ara_toplam'])
  <tr><td class="lbl">{{ __('Ara toplam') }}</td><td class="val">{{ $money($document->subtotal) }}</td></tr>
  @endif
  @if((float) $document->discount_total > 0 && $toplam['iskonto'])
  <tr><td class="lbl">{{ __('İskonto') }}</td><td class="val">-{{ $money($document->discount_total) }}</td></tr>
  @endif
  @if($toplam['kdv'])
  <tr><td class="lbl">{{ __('KDV') }}</td><td class="val">{{ $money($document->tax_total) }}</td></tr>
  @endif
  <tr class="grand"><td class="lbl">{{ __('TOPLAM') }} ({{ $document->currency_code }})</td><td class="val">{{ $money($document->grand_total) }}</td></tr>
</table>
