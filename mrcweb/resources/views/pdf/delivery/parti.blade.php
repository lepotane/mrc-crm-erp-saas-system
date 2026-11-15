<table class="layout" data-blok="parti" data-idx="{{ $idx ?? 0 }}" style="margin-bottom: 4px;">
  <tr>
    <td style="width: 50%; vertical-align: top; padding-right: 6px;">
      <div class="infobox">
        <h3>{{ $document->type === 'purchase' ? __('Tedarikçi') : __('Müşteri') }}</h3>
        <div class="name">{{ $party?->name ?? '' }}</div>
        @if($party?->code)<div class="row"><span class="lbl">{{ __('Kod') }}:</span> {{ $party->code }}</div>@endif
        @if($party?->address)<div class="row">{{ $party->address }}</div>@endif
        @if($party?->phone)<div class="row"><span class="lbl">{{ __('Telefon') }}:</span> {{ $party->phone }}</div>@endif
      </div>
    </td>
    <td style="width: 50%; vertical-align: top; padding-left: 6px;">
      <div class="infobox">
        <h3>{{ __('Belge bilgileri') }}</h3>
        <div class="row"><span class="lbl">{{ __('Fatura no') }}:</span> {{ $document->serial }}</div>
        <div class="row"><span class="lbl">{{ __('Sevk tarihi') }}:</span> {{ $date($document->issue_date) }}</div>
        @if($document->warehouse_id)<div class="row"><span class="lbl">{{ __('Depo') }}:</span> {{ $document->warehouse?->name ?? '' }}</div>@endif
      </div>
    </td>
  </tr>
</table>
