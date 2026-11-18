@php($lines = session()->has('quote_request_receipt') ? 0 : count(session('commerce_cart', [])))
<a class="quote-tab" data-quote-tab href="{{ route('shop.request') }}#seleccion" @if(!$lines) hidden @endif aria-label="Revisar cotización, {{ $lines }} partidas">
    <x-shop.icon name="bag"/><span>Revisar cotización</span><b data-cart-count>{{ $lines }}</b>
</a>
