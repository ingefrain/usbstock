{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<url><loc>{{ route('shop.home') }}</loc></url>
<url><loc>{{ route('shop.catalog') }}</loc></url>
<url><loc>{{ route('shop.info') }}</loc></url>
@foreach($products as $product)
<url><loc>{{ route('shop.product',$product->slug) }}</loc><lastmod>{{ $product->updated_at->toAtomString() }}</lastmod></url>
@endforeach
@foreach($categories as $category)
<url><loc>{{ route('shop.category',$category->slug) }}</loc><lastmod>{{ $category->updated_at->toAtomString() }}</lastmod></url>
@endforeach
</urlset>
