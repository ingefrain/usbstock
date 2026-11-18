@props(['paginator', 'elements' => []])
@php
    $counted = $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;
    $options = request()->attributes->get('admin_pagination_options', [$paginator->perPage()]);
    $query = \Illuminate\Support\Arr::except(request()->query(), ['page', 'per_page']);
    $hidden = array_filter(explode('&', \Illuminate\Support\Arr::query($query)));
@endphp
<section class="admin-pagination" aria-label="Paginación de resultados" data-admin-pagination>
    <p class="admin-pagination-info" role="status">
        @if($counted)
            @if($paginator->total()) Mostrando {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} de {{ number_format($paginator->total()) }}
            @else Sin resultados @endif
        @else Navegación de resultados @endif
    </p>
    <nav class="admin-pagination-pages" aria-label="Páginas">
        @if($counted && $paginator->lastPage() > 1)
            @if($paginator->onFirstPage())<span class="page-control" aria-disabled="true">Primera</span>@else<a class="page-control" href="{{ $paginator->url(1) }}">Primera</a>@endif
        @endif
        @if($paginator->onFirstPage())<span class="page-control" aria-disabled="true">Anterior</span>@else<a class="page-control" href="{{ $paginator->previousPageUrl() }}" rel="prev"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Anterior</a>@endif
        @if($counted)
            <span class="page-numbers">
            @foreach($elements as $element)
                @if(is_string($element))<span class="page-gap" aria-hidden="true">{{ $element }}</span>
                @else @foreach($element as $page => $url)
                    @if($page === $paginator->currentPage())<span class="page-number is-current" aria-current="page" aria-label="Página {{ $page }}, actual">{{ $page }}</span>
                    @else<a class="page-number" href="{{ $url }}" aria-label="Ir a la página {{ $page }}">{{ $page }}</a>@endif
                @endforeach @endif
            @endforeach
            </span>
        @endif
        @if($paginator->hasMorePages())<a class="page-control" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>@else<span class="page-control" aria-disabled="true">Siguiente</span>@endif
        @if($counted && $paginator->lastPage() > 1)
            @if($paginator->hasMorePages())<a class="page-control" href="{{ $paginator->url($paginator->lastPage()) }}">Última</a>@else<span class="page-control" aria-disabled="true">Última</span>@endif
        @endif
    </nav>
    <div class="admin-pagination-preferences">
        @if($counted)
        <form method="GET" action="{{ request()->url() }}">
            @foreach($hidden as $pair) @php([$name, $value] = array_pad(explode('=', $pair, 2), 2, ''))<input type="hidden" name="{{ urldecode($name) }}" value="{{ urldecode($value) }}"> @endforeach
            <input type="hidden" name="per_page" value="{{ $paginator->perPage() }}">
            <label>Ir a la página
                @if($paginator->lastPage() <= 200)<select name="page">@for($page = 1; $page <= $paginator->lastPage(); $page++)<option value="{{ $page }}" @selected($page === $paginator->currentPage())>{{ $page }}</option>@endfor</select>
                @else<input type="number" name="page" min="1" max="{{ $paginator->lastPage() }}" value="{{ $paginator->currentPage() }}" required>@endif
            </label><button type="submit">Ir</button>
        </form>
        @endif
        <form method="GET" action="{{ request()->url() }}">
            @foreach($hidden as $pair) @php([$name, $value] = array_pad(explode('=', $pair, 2), 2, ''))<input type="hidden" name="{{ urldecode($name) }}" value="{{ urldecode($value) }}"> @endforeach
            <input type="hidden" name="page" value="1">
            <label>Registros por página<select name="per_page">@foreach($options as $size)<option value="{{ $size }}" @selected($size === $paginator->perPage())>{{ $size }}</option>@endforeach</select></label>
            <button type="submit">Aplicar</button>
        </form>
    </div>
</section>
