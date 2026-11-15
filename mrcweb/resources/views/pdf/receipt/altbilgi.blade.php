<div class="foot center" data-blok="altbilgi" data-idx="{{ $idx ?? 0 }}">
  @if(! empty($altbilgi))
  {{ $altbilgi }}
  @else
  {{ __('Bizi tercih ettiğiniz için teşekkürler.') }}<br>
  {{ __('Bu fiş mali belge yerine geçmez.') }}
  @endif
</div>
