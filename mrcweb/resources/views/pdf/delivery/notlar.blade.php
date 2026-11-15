@if($document->notes)
<div class="notes" data-blok="notlar" data-idx="{{ $idx ?? 0 }}">
  <h3>{{ __('Not') }}</h3>
  <div>{{ $document->notes }}</div>
</div>
@endif
