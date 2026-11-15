<table class="totals" data-blok="toplam" data-idx="{{ $idx ?? 0 }}">
  @if($toplam['ara_toplam'])
  <tr>
    <td class="lbl">{{ __('Ara toplam') }}:</td>
    <td class="val">{{ $money($document->subtotal) }} {{ $document->currency_code }}</td>
  </tr>
  @endif
  @if($toplam['iskonto'])
  <tr>
    <td class="lbl">{{ __('İskonto') }}:</td>
    <td class="val">{{ $money($document->discount_total) }} {{ $document->currency_code }}</td>
  </tr>
  @endif
  @if($toplam['kdv'])
  <tr>
    <td class="lbl">{{ __('KDV') }}:</td>
    <td class="val">{{ $money($document->tax_total) }} {{ $document->currency_code }}</td>
  </tr>
  @endif
  <tr class="grand">
    <td class="lbl">{{ __('Genel toplam') }}:</td>
    <td class="val">{{ $money($document->grand_total) }} {{ $document->currency_code }}</td>
  </tr>
  @if($paid > 0)
  <tr>
    <td class="lbl">{{ __('Tahsil edilen') }}:</td>
    <td class="val">{{ $money($paid) }} {{ $document->currency_code }}</td>
  </tr>
  <tr>
    {{-- v1'de taşma (overpayment) engeli yok: eksi bakiye yerine açık etiket gösterilir --}}
    <td class="lbl">{{ $remaining < 0 ? __('Fazla ödeme') : __('Kalan bakiye') }}:</td>
    <td class="val">{{ $money(abs($remaining)) }} {{ $document->currency_code }}</td>
  </tr>
  @endif
</table>
