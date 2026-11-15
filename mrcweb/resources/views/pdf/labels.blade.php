<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Ürün etiketleri') }}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; margin: 0; padding: 0; }
  /* Rulo etiket: ölçü sunucudan gelir (38×25 varsayılan, 40×30, 50×30).
     İsim en çok 2 satırdır (mm kapak + taşan gizli); kod+fiyat tek alt satırdadır.
     .label'e SABİT yükseklik VERİLMEZ: kağıt boyuna eşit kutu dompdf'te
     fazladan boş sayfa üretiyordu. Barkodda YALNIZCA genişlik verilir:
     dompdf boy+yükseklik birlikte verilince genişliği yok sayıp kutuyu
     genişletiyordu; oran korunarak hedefe sığar. */
  .label { width: {{ $dims[0] }}mm; padding: 1mm 1.5mm; page-break-after: always; overflow: hidden; }
  .label:last-child { page-break-after: avoid; }
  .name { font-size: {{ $dims[1] >= 30 ? 8 : 7 }}px; font-weight: bold; line-height: 1.25; height: {{ $dims[1] >= 30 ? 5.6 : 5.2 }}mm; overflow: hidden; word-wrap: break-word; }
  .codeimg { display: block; width: {{ $dims[0] - 5 }}mm; margin: 0.5mm 0; }
  table.bottom { width: {{ $dims[0] - 3 }}mm; border-collapse: collapse; }
  table.bottom td { padding: 0; vertical-align: middle; white-space: nowrap; }
  .code { font-size: 7px; font-family: 'DejaVu Sans Mono', monospace; text-align: left; }
  .price { font-size: {{ $dims[1] >= 30 ? 9 : 8 }}px; font-weight: bold; text-align: right; }
  /* Tasarımcı bestesi: resim zaten tam ölçüdedir; yalnız genişlik verilir
     (dompdf boy+yükseklik birlikte verilince genişliği yok sayar).
     Kâğıt payı için 1mm dar basılır: birebir ölçü dompdf'te fazladan
     boş sayfa üretiyordu; sarmalayıcı dolgusuzdur (kenar bestede var). */
  .labelbare { width: {{ $dims[0] }}mm; padding: 0; page-break-after: always; overflow: hidden; }
  .labelbare:last-child { page-break-after: avoid; }
  .composed { display: block; width: {{ $dims[0] - 1 }}mm; }
</style>
</head>
<body>
@foreach($labels as $label)
@if(! empty($label['composed']))
  {{-- Tasarımcı bestesi: tek resim, dolgusuz kutu (kenar boşlukları bestede). --}}
  <div class="labelbare"><img class="composed" src="{{ $label['image'] }}"></div>
@else
<div class="label">
  <div class="name">{{ $label['name'] }}</div>
  <img class="codeimg" src="{{ $label['image'] }}">
  <table class="bottom"><tr>
    <td class="code">{{ $label['code'] }}</td>
    @if($showPrice)
    <td class="price">{{ $money($label['price']) }}</td>
    @endif
  </tr></table>
</div>
@endif
@endforeach
</body>
</html>
