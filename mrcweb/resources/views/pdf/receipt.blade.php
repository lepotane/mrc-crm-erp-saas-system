<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Satış fişi') }} {{ $document->serial }}</title>
@php
  $stil = ($tpl ?? null)['style'] ?? [];
  $altbilgi = $stil['footerText'] ?? null;
  $fontOlcek = $stil['fontScale'] ?? 'normal';
  $govdeFont = $fontOlcek === 'compact' ? 9 : ($fontOlcek === 'large' ? 11 : 10);
  $toplam = array_merge(['ara_toplam' => true, 'iskonto' => true, 'kdv' => true], $stil['totals'] ?? []);
  $sira = ($tpl ?? null)['blocks'] ?? [['key' => 'kalemler'], ['key' => 'toplam'], ['key' => 'odeme'], ['key' => 'altbilgi']];
@endphp
<style>
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $govdeFont }}px; color: #111111; margin: 0; padding: 6px; }
  @if($size === 'a5')
  @page { size: A5; margin: 28px 30px; }
  @else
  @page { margin: 8px 8px; }
  @endif
  .center { text-align: center; }
  .logo { max-height: 40px; max-width: 140px; margin-bottom: 2px; }
  .firm { font-size: 13px; font-weight: bold; }
  .sub { font-size: 8.5px; color: #444444; }
  .title { font-size: 12px; font-weight: bold; letter-spacing: 1px; margin: 6px 0 2px 0; border-top: 1px dashed #111111; border-bottom: 1px dashed #111111; padding: 4px 0; }
  .meta { font-size: 9px; margin: 2px 0; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.items td { padding: 3px 0; font-size: 9.5px; vertical-align: top; border-bottom: 1px dotted #999999; }
  td.num { text-align: right; white-space: nowrap; }
  table.totals { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.totals td { padding: 2px 0; font-size: 10px; }
  table.totals td.lbl { text-align: right; color: #333333; width: 62%; }
  table.totals td.val { text-align: right; width: 38%; font-weight: bold; }
  tr.grand td { font-size: 12px; border-top: 1px dashed #111111; padding-top: 4px; }
  .pay { margin-top: 6px; border-top: 1px dashed #111111; padding-top: 4px; font-size: 9.5px; }
  .foot { margin-top: 8px; font-size: 8px; color: #555555; }
</style>
</head>
<body>
<div class="center">
  @if(! empty($logoFile))
  <img src="{{ $logoFile }}" class="logo"><br>
  @endif
  <div class="firm">{{ $company?->name ?? '' }}</div>
  @if($company?->address)
  <div class="sub">{{ $company->address }}</div>
  @endif
  @if($company?->phone)
  <div class="sub">{{ $company->phone }}</div>
  @endif
  <div class="title">{{ __('Satış fişi') }}</div>
</div>
<div class="meta">{{ __('Fiş no') }}: <b>{{ $document->serial }}</b></div>
<div class="meta">{{ __('Tarih') }}: {{ $date($document->issue_date) }} {{ $document->created_at?->format('H:i') }}</div>
<div class="meta">{{ __('Müşteri') }}: {{ $party?->name ?? '—' }}</div>
@foreach($sira as $b)
  @if(($b['key'] ?? '') === 'ozel')
    @include('pdf.blocks.ozel', ['oge' => $b, 'idx' => $loop->index])
  @else
    @include('pdf.receipt.'.$b['key'], ['idx' => $loop->index])
  @endif
@endforeach
</body>
</html>
