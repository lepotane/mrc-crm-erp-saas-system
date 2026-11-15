<table class="items" data-blok="kalemler" data-idx="{{ $idx ?? 0 }}">
  @foreach($document->items as $item)
  <tr>
    <td>
      {{ $item->display_name ?? $item->name }}<br>
      <span style="font-size: 8.5px; color: #444444;">{{ $qty($item->quantity) }} x {{ $money($item->unit_price) }}{{ $item->discount_rate > 0 ? ' (-%'.$qty($item->discount_rate).')' : '' }}</span>
    </td>
    <td class="num">{{ $money($item->line_total) }}</td>
  </tr>
  @endforeach
</table>
