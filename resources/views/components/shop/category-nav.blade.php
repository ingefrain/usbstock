@props(['mobile' => false])
<nav class="category-nav {{ $mobile ? 'category-nav-mobile' : 'category-nav-desktop' }}" aria-label="{{ $mobile ? 'Categorías en móvil' : 'Categorías comerciales' }}">
@foreach($navigationCategories as $rootCategory)
    @php($currentSlug = request()->route('category') ?? request('category'))
    @php($active = $rootCategory->slug === $currentSlug || $rootCategory->children->contains('slug', $currentSlug))
    @if($rootCategory->children->isNotEmpty())
    <details class="category-group" @if($active) data-current @endif>
        <summary aria-expanded="false">{{ $rootCategory->name }} <span aria-hidden="true">⌄</span></summary>
        <div class="category-dropdown">
            <div class="category-intro"><span class="eyebrow">Explora posibilidades</span><strong>{{ $rootCategory->name }}</strong><a href="{{ route('shop.category', $rootCategory->slug) }}" @if($rootCategory->slug === $currentSlug) aria-current="page" @endif>Ver todo <span aria-hidden="true">↗</span></a></div>
            <div class="category-links">@foreach($rootCategory->children as $child)<a href="{{ route('shop.category', $child->slug) }}" @if($child->slug === $currentSlug) aria-current="page" @endif>{{ $child->name }} <span aria-hidden="true">↗</span></a>@endforeach</div>
        </div>
    </details>
    @else<a href="{{ route('shop.category', $rootCategory->slug) }}" @if($active) aria-current="page" @endif>{{ $rootCategory->name }}</a>@endif
@endforeach
</nav>
