<button class="corporate-trigger" type="button" data-contacts-open aria-label="Contactar por WhatsApp" aria-expanded="false" aria-controls="corporate-contacts" hidden>
<img src="{{ asset('brand/whatsapp-icon.png') }}" width="60" height="60" alt="">
</button>
<section id="corporate-contacts" class="corporate-panel" role="region" aria-labelledby="corporate-title" data-contacts data-list-url="{{ route('shop.contacts') }}" data-click-url="{{ route('shop.contacts.click') }}" hidden>
    <header><h2 id="corporate-title" class="sr-only">Ejecutivos de ventas por WhatsApp</h2><img src="{{ asset('brand/logo.webp') }}" width="150" height="34" alt="USB Stock"><button type="button" data-contacts-close aria-label="Cerrar contactos"><x-shop.icon name="close"/></button></header>
    <img class="corporate-banner" src="{{ asset('brand/whatsapp-banner.webp') }}" width="1080" height="1080" alt="Envíanos un WhatsApp">
    <div class="corporate-body"><p role="status" aria-live="polite" data-contacts-status>Cargando ejecutivos…</p><ul class="corporate-list" data-contacts-list></ul><div class="corporate-recovery" data-contacts-recovery hidden><button type="button" data-contacts-retry>Volver a consultar</button><a href="{{ route('shop.info') }}">Atención comercial</a></div></div>
</section>
