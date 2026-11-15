{{-- Serbest öğeler (tasarımcı "özel" blokları). data-blok/data-idx: görsel
     tasarımcı tuvalinde tıklama-seçim içindir (PDF çıktısını etkilemez). --}}
@if(($oge['tip'] ?? 'metin') === 'cizgi')
<div data-blok="ozel" data-tip="cizgi" data-idx="{{ $idx ?? 0 }}" style="border-top: 1px solid #94a3b8; margin: 10px 0;"></div>
@elseif(($oge['tip'] ?? 'metin') === 'bosluk')
<div data-blok="ozel" data-tip="bosluk" data-idx="{{ $idx ?? 0 }}" style="height: {{ max(2, min(30, (float) ($oge['yukseklik'] ?? 8))) }}mm;"></div>
@else
<div class="notes" data-blok="ozel" data-tip="metin" data-idx="{{ $idx ?? 0 }}"><div>{!! nl2br(e($oge['text'] ?? '')) !!}</div></div>
@endif
