<!doctype html>
<html lang="{{ app()->getLocale() }}">
@php
  // Karar 48: şablon varsa blok sırası/görünürlük/stil ordan gelir; yoksa yerleşik.
  $stil = ($tpl ?? null)['style'] ?? [];
  $hdr = $stil['headerColor'] ?? '#1e3a5f';
  $logoSag = ($stil['logoPos'] ?? 'left') === 'right';
  $logoGoster = array_key_exists('logoVisible', $stil) ? (bool) $stil['logoVisible'] : true;
  $altbilgi = $stil['footerText'] ?? null;
  $fontOlcek = $stil['fontScale'] ?? 'normal';
  $govdeFont = $fontOlcek === 'compact' ? 10 : ($fontOlcek === 'large' ? 12 : 11);
  $sutun = array_merge(
    ['miktar' => true, 'birim' => true, 'birim_fiyat' => true, 'iskonto' => true, 'kdv' => true, 'satir_toplam' => true],
    $stil['columns'] ?? []
  );
  $toplam = array_merge(['ara_toplam' => true, 'iskonto' => true, 'kdv' => true], $stil['totals'] ?? []);
  $sira = ($tpl ?? null)['blocks'] ?? [['key' => 'parti'], ['key' => 'kalemler'], ['key' => 'toplam'], ['key' => 'notlar']];
@endphp
<head>
<meta charset="utf-8">
<title>{{ $document->serial }}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $govdeFont }}px; color: #1f2937; margin: 0; padding: 0; }
  @page { margin: 122px 36px 70px 36px; }
  /* Sabit başlık içerik alanına TAŞAMAZ: üst pay 122px, başlık -102px'ten başlar
     (≈20px sayfa boşluğu), bütçe 102px. Başlık ~65px tutulur (sıkı satırlar, hapsız
     durum rozeti, küçük logo) — firma adı 2 satıra sarsa bile çizgi metne girmez. */
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
  .watermark { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 84px; font-weight: bold; color: #ececec; }
  table.layout { width: 100%; border-collapse: collapse; }
  .infobox { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
  /* CSS büyük harfe çevirme özelliği KULLANILMAZ: dompdf bunu Türkçe kurallarıyla
     uygulamıyor („Belge BILGILERI", „CARI" üretiyordu). Başlık havası letter-spacing
     ile verilir; Türkçe yazım (İ/ı) bozulmaz. */
  .infobox h3 { margin: 0 0 5px 0; font-size: 10.5px; font-weight: bold; color: {{ $hdr }}; letter-spacing: 0.7px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
  .infobox .name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
  .infobox .row { margin: 1px 0; color: #4b5563; }
  .infobox .lbl { color: #9ca3af; }
  .items { width: 100%; border-collapse: collapse; margin-top: 14px; }
  .items th { background: {{ $hdr }}; color: #ffffff; font-size: 9px; padding: 6px 6px; text-align: left; }
  .items th.num, .items td.num { text-align: right; }
  .items td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
  .items tr:nth-child(even) td { background: #f8fafc; }
  .totals { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .totals td { padding: 3px 6px; font-size: 10px; }
  .totals td.lbl { text-align: right; color: #4b5563; width: 78%; }
  .totals td.val { text-align: right; width: 22%; }
  .totals tr.grand td { font-size: 12px; font-weight: bold; color: {{ $hdr }}; border-top: 2px solid {{ $hdr }}; padding-top: 6px; }
  .notes { margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
  .notes h3 { margin: 0 0 4px 0; font-size: 10.5px; font-weight: bold; color: {{ $hdr }}; letter-spacing: 0.7px; }
  .credit { margin-top: 10px; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>

@if($document->status === 'draft')
<div class="watermark">{{ __('TASLAK') }}</div>
@endif

<div class="header">
  <table class="layout">
    <tr>
      <td style="width: 60%; vertical-align: top;">
        @if(! $logoSag && $logoGoster && ! empty($logoFile))
        <img src="{{ $logoFile }}" style="max-height: 40px; max-width: 180px; margin-bottom: 3px;">
        @endif
        <div class="firm">{{ $company?->name ?? '' }}</div>
        <div class="firm-sub">
          @php
            // country varsayılanı „TR" gibi 2 harfli kod: adres/şehir yokken başına
            // anlamsız bir satır üretmesin; tam ad („Türkiye") yazılmışsa gösterilir.
            $addr = array_filter([
                $company?->address,
                $company?->city,
                strlen((string) ($company?->country ?? '')) > 2 ? $company->country : null,
            ]);
          @endphp
          @if(count($addr)){{ implode(', ', $addr) }}<br>@endif
          @if($company?->tax_office){{ __('Vergi dairesi') }}: {{ $company->tax_office }}<br>@endif
          @if($company?->tax_number){{ __('Vergi no') }}: {{ $company->tax_number }}<br>@endif
          @if($company?->phone || $company?->email){{ trim(($company->phone ?? '').(($company->phone && $company->email) ? ' · ' : '').($company->email ?? '')) }}@endif
        </div>
      </td>
      <td class="docbox" style="width: 40%; vertical-align: top;">
        @if($logoSag && $logoGoster && ! empty($logoFile))
        <img src="{{ $logoFile }}" style="max-height: 40px; max-width: 180px; margin-bottom: 3px;">
        @endif
        <div class="doctype">{{ $typeLabel }}</div>
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
    @include('pdf.doc.'.$b['key'], ['idx' => $loop->index])
  @endif
@endforeach

<div class="credit">{{ $altbilgi ?? __('Bu belge MRC ile oluşturulmuştur.') }}</div>

</body>
</html>
