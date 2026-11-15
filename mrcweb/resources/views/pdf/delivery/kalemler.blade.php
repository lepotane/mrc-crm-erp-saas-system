<table class="items" data-blok="kalemler" data-idx="{{ $idx ?? 0 }}">
  <thead>
    <tr>
      <th style="width: 8%;">{{ __('Sıra') }}</th>
      <th>{{ __('Ad') }}</th>
      <th class="num">{{ __('Miktar') }}</th>
      <th>{{ __('Birim') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach($document->items as $i => $item)
    <tr>
      <td class="num">{{ $i + 1 }}</td>
      <td>{{ $item->display_name ?? $item->name }}</td>
      <td class="num">{{ $qty($item->quantity) }}</td>
      <td>{{ $item->unit_display_name ?? $item->product?->unit?->name ?? '' }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
