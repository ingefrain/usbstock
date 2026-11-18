<button class="corporate-trigger" type="button" data-contacts-open aria-label="Contactar por WhatsApp" aria-haspopup="dialog" aria-controls="corporate-contacts" hidden>
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8.5 8.5 0 0 1-12.7 7.4L3 20l1.1-4.3A8.5 8.5 0 1 1 20 11.5Z"/><path d="M8 7.5c-.8 1.3.2 3.7 2 5.5s4.2 2.8 5.5 2l1-1.4-2.6-1.4-1 1c-1.4-.6-2.6-1.8-3.2-3.2l1-1-1.4-2.6Z"/></svg>
</button>
<dialog id="corporate-contacts" class="corporate-panel" aria-labelledby="corporate-title" data-contacts data-list-url="{{ route('shop.contacts') }}" data-click-url="{{ route('shop.contacts.click') }}">
    <header><img src="{{ asset('brand/logo.webp') }}" width="176" height="40" alt="USB Stock"><button type="button" data-contacts-close aria-label="Cerrar contactos"><x-shop.icon name="close"/></button></header>
    <div class="corporate-heading"><span class="eyebrow">Hablemos de tu proyecto</span><h2 id="corporate-title">Tu próximo contacto.</h2><p>Elige a un asesor del corporativo para conversar por WhatsApp.</p></div>
    <div class="corporate-body"><p role="status" aria-live="polite" data-contacts-status>Consultando disponibilidad…</p><ul class="corporate-list" data-contacts-list></ul><button class="button secondary" type="button" data-contacts-retry hidden>Volver a consultar</button></div>
    <footer><span>Atención personal, a tu ritmo.</span><a href="{{ route('shop.info') }}">Más formas de contacto ↗</a></footer>
</dialog>
