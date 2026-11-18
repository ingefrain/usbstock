@if(config('commerce.review_mode'))
<div class="review-notice" role="note">Revisión aislada · Nombres y fotografías de referencia histórica. Existencias y operaciones de prueba, sin oferta comercial vigente.</div>
@elseif(app()->environment('local'))
<div class="review-notice" role="note">ENTORNO DE DESARROLLO — Información de catálogo de referencia. Existencias y costos sujetos a actualización.</div>
@endif
