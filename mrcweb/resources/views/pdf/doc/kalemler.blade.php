<table class="items" data-blok="kalemler" data-idx="{{ $idx ?? 0 }}">
  <thead>
    <tr>
      <th>{{ __('Ad') }}</th>
      @if($sutun['miktar'])<th class="num">{{ __('Miktar') }}</th>@endif
      @if($sutun['birim'])<th>{{ __('Birim') }}</th>@endif
      @if($sutun['birim_fiyat'])<th class="num">{{ __('Birim fiyat') }}</th>@endif
      @if($sutun['iskonto'])<th class="num">{{ __('İskonto') }}</th>@endif
      @if($sutun['kdv'])<th class="num">{{ __('KDV%') }}</th>@endif
      @if($sutun['kdv'])<th class="num">{{ __('KDV') }}</th>@endif
      @if($sutun['satir_toplam'])<th class="num">{{ __('Satır toplamı') }}</th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach($document->items as $item)
    <tr>
      <td>{{ $item->display_name ?? $item->name }}</td>
      @if($sutun['miktar'])<td class="num">{{ $qty($item->quantity) }}</td>@endif
      @if($sutun['birim'])<td>{{ $item->unit_display_name ?? $item->product?->unit?->name ?? '' }}</td>@endif
      @if($sutun['birim_fiyat'])<td class="num">{{ $money($item->unit_price) }}</td>@endif
      @if($sutun['iskonto'])<td class="num">{{ $money($item->line_discount) }}</td>@endif
      @if($sutun['kdv'])<td class="num">%{{ $qty($item->tax_rate) }}</td>@endif
      @if($sutun['kdv'])<td class="num">{{ $money($item->line_tax) }}</td>@endif
      @if($sutun['satir_toplam'])<td class="num">{{ $money($item->line_total) }}</td>@endif
    </tr>
    @endforeach
  </tbody>
</table>
