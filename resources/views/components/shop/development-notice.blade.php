@if(config('commerce.visual_snapshot'))
<div class="review-notice" role="note">Revisión visual aislada · Copia local del catálogo. Las operaciones de prueba no generan solicitudes comerciales reales.</div>
@elseif(config('commerce.review_mode'))
<div class="review-notice" role="note">Revisión aislada · Nombres y fotografías de referencia histórica. Existencias y operaciones de prueba, sin oferta comercial vigente.</div>
@elseif(app()->environment('local'))
<div class="review-notice" role="note">ENTORNO DE DESARROLLO — Información de catálogo de referencia. Existencias y costos sujetos a actualización.</div>
@endif
