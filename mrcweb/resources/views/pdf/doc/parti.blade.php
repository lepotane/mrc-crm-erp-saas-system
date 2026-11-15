<table class="layout" data-blok="parti" data-idx="{{ $idx ?? 0 }}" style="margin-bottom: 4px;">
  <tr>
    <td style="width: 50%; vertical-align: top; padding-right: 6px;">
      <div class="infobox">
        <h3>{{ __('Cari') }}</h3>
        <div class="name">{{ $party?->name ?? '' }}</div>
        @if($party?->code)<div class="row"><span class="lbl">{{ __('Kod') }}:</span> {{ $party->code }}</div>@endif
        @if($party?->address)<div class="row">{{ $party->address }}</div>@endif
        @if($party?->phone)<div class="row"><span class="lbl">{{ __('Telefon') }}:</span> {{ $party->phone }}</div>@endif
        @if($party?->tax_number)<div class="row"><span class="lbl">{{ __('Vergi no') }}:</span> {{ $party->tax_number }}</div>@endif
      </div>
    </td>
    <td style="width: 50%; vertical-align: top; padding-left: 6px;">
      <div class="infobox">
        <h3>{{ __('Belge bilgileri') }}</h3>
        <div class="row"><span class="lbl">{{ __('Seri no') }}:</span> {{ $document->serial }}</div>
        <div class="row"><span class="lbl">{{ __('Kesim tarihi') }}:</span> {{ $date($document->issue_date) }}</div>
        @if($document->due_date)<div class="row"><span class="lbl">{{ __('Vade tarihi') }}:</span> {{ $date($document->due_date) }}</div>@endif
        <div class="row"><span class="lbl">{{ __('Para birimi') }}:</span> {{ $document->currency_code }}</div>
        <div class="row"><span class="lbl">{{ __('Ödeme durumu') }}:</span> {{ $statusLabel }}</div>
      </div>
    </td>
  </tr>
</table>
