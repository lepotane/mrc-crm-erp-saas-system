<!doctype html>
<html lang="{{ app()->getLocale() }}">
@php
  $stil = ($tpl ?? null)['style'] ?? [];
  $hdr = $stil['headerColor'] ?? '#1e3a5f';
  $logoSag = ($stil['logoPos'] ?? 'left') === 'right';
  $logoGoster = array_key_exists('logoVisible', $stil) ? (bool) $stil['logoVisible'] : true;
  $altbilgi = $stil['footerText'] ?? null;
  $fontOlcek = $stil['fontScale'] ?? 'normal';
  $govdeFont = $fontOlcek === 'compact' ? 10 : ($fontOlcek === 'large' ? 12 : 11);
  $sira = ($tpl ?? null)['blocks'] ?? [['key' => 'parti'], ['key' => 'kalemler'], ['key' => 'imza'], ['key' => 'notlar']];
@endphp
<head>
<meta charset="utf-8">
<title>{{ __('İrsaliye') }} {{ $document->serial }}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $govdeFont }}px; color: #1f2937; margin: 0; padding: 0; }
  @page { margin: 122px 36px 70px 36px; }
  /* Başlık bütçesi document.blade ile aynı (102px): çizgi asla gövdeye girmez. */
  .header { position: fixed; top: -102px; left: 0; right: 0; border-bottom: 2px solid {{ $hdr }}; padding-bottom: 6px; }
  .header .firm { font-size: 16px; font-weight: bold; color: {{ $hdr }}; }
  .header .firm-sub { font-size: 9px; color: #6b7280; margin-top: 1px; }
  .header .docbox { text-align: right; }
  .header .doctype { display: inline-block; background: {{ $hdr }}; color: #ffffff; font-size: 12px; font-weight: bold; padding: 3px 10px; border-radius: 3px; }
  .header .serial { font-size: 12px; font-weight: bold; color: {{ $hdr }}; margin-top: 3px; }
  .header .status { font-size: 9px; color: #4b5563; margin-top: 3px; }
  .footer { position: fixed; bottom: -50px; left: 0; right: 0; border-top: 1px solid #d1d5db; padding-top: 6px; font-size: 8px; color: #9ca3af; }
  .footer .pagenum:before { content: counter(page); }
  .footer .pagecount:before { content: counter(pages); }
  table.layout { width: 100%; border-collapse: collapse; }
  .infobox { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
  .infobox h3 { margin: 0 0 5px 0; font-size: 10.5px; font-weight: bold; color: {{ $hdr }}; letter-spacing: 0.7px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
  .infobox .name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
  .infobox .row { margin: 1px 0; color: #4b5563; }
  .infobox .lbl { color: #9ca3af; }
  .items { width: 100%; border-collapse: collapse; margin-top: 14px; }
  .items th { background: {{ $hdr }}; color: #ffffff; font-size: 9px; padding: 6px 6px; text-align: left; }
  .items th.num, .items td.num { text-align: right; }
  .items td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
  .items tr:nth-child(even) td { background: #f8fafc; }
  .notes { margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
  .notes h3 { margin: 0 0 4px 0; font-size: 10.5px; font-weight: bold; color: {{ $hdr }}; letter-spacing: 0.7px; }
  .sign { margin-top: 48px; }
  .sign td { width: 50%; text-align: center; font-size: 10px; color: #4b5563; }
  .sign .line { margin: 0 24px; padding-top: 40px; border-top: 1px solid #374151; }
</style>
</head>
<body>

<div class="header">
  <table class="layout">
    <tr>
      <td style="width: 60%; vertical-align: top;">
        @if(! $logoSag && $logoGoster && ! empty($logoFile))
        <img src="{{ $logoFile }}" style="max-height: 40px; max-width: 180px; margin-bottom: 3px;">
        @endif
        <div class="firm">{{ $company?->name ?? '' }}</div>
        <div class="firm-sub">
          @if($company?->phone || $company?->email){{ trim(($company->phone ?? '').(($company->phone && $company->email) ? ' · ' : '').($company->email ?? '')) }}@endif
        </div>
      </td>
      <td class="docbox" style="width: 40%; vertical-align: top;">
        @if($logoSag && $logoGoster && ! empty($logoFile))
        <img src="{{ $logoFile }}" style="max-height: 40px; max-width: 180px; margin-bottom: 3px;">
        @endif
        <div class="doctype">{{ __('İrsaliye') }}</div>
        <div class="serial">{{ $document->serial }}</div>
        <div><span class="status">{{ $statusLabel }}</span></div>
      </td>
    </tr>
  </table>
</div>

<div class="footer">
  <table class="layout">
    <tr>
      <td>{{ $altbilgi ?? __('Bu belge MRC ile oluşturulmuştur.') }}</td>
      <td style="text-align: right;">{{ __('Sayfa') }} <span class="pagenum"></span> / <span class="pagecount"></span></td>
    </tr>
  </table>
</div>

@foreach($sira as $b)
  @if(($b['key'] ?? '') === 'ozel')
    @include('pdf.blocks.ozel', ['oge' => $b, 'idx' => $loop->index])
  @else
    @include('pdf.delivery.'.$b['key'], ['idx' => $loop->index])
  @endif
@endforeach

</body>
</html>
